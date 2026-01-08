package main

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net"
	"net/rpc"
	"os/exec"
	"time"

	"github.com/moby/buildkit/client/llb"
	dockerfile "github.com/moby/buildkit/frontend/dockerfile/builder"
	"github.com/moby/buildkit/frontend/gateway/client"
	"github.com/pkg/errors"
)

func build(ctx context.Context, c client.Client) (*client.Result, error) {
	// transform opts into json
	jsonString, err := json.Marshal(c.BuildOpts().Opts)

	if err != nil {
		return nil, err
	}

	transform, err := CreateTransform(jsonString, ctx, c)

	if err != nil {
		return nil, err
	}

	if err := InjectDockerfileTransform(transform.transformDockerfile, c); err != nil {
		return nil, err
	}

	// Pass control to the upstream Dockerfile frontend
	return dockerfile.Build(ctx, c)
}

type Transform struct {
	options []byte
	client  client.Client
}

func (s *Transform) Hi(name string, r *string) error {
	*r = fmt.Sprintf("Hello, %s!", name)
	return nil
}

func (s *Transform) transformDockerfile(dockerfile []byte) ([]byte, error) {
	cmd := exec.Command("castor", "transform-docker-file", string(s.options))
	cmd.Stdin = bytes.NewReader(dockerfile)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		if stderr.Len() > 0 {
			return nil, fmt.Errorf("%s", stderr.String())
		}

		return nil, err
	}

	return stdout.Bytes(), nil
}

func CreateTransform(options []byte, ctx context.Context, client client.Client) (*Transform, error) {
	ln, err := net.Listen("tcp", ":6001")

	if err != nil {
		return nil, err
	}

	transform := new(Transform)
	transform.options = options
	transform.client = client

	err = rpc.Register(transform)

	if err != nil {
		return nil, err
	}

	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				continue
			}
			_ = conn

			go func() {
				// ensure connection is closed after use
				defer func(conn net.Conn) {
					err := conn.Close()
					if err != nil {
						panic(err)
					}
				}(conn)

				var length uint32

				for {
					err := binary.Read(conn, binary.BigEndian, &length)

					if err != nil {
						return
					}

					buf := make([]byte, length)

					err = binary.Read(conn, binary.BigEndian, &buf)

					if err != nil {
						return
					}

					// lookup for context file
					var request LoadFileRequest
					err = json.Unmarshal(buf, &request)

					if err != nil {
						return
					}

					fileContent, err := loadFileFromContext(ctx, client, request.Context, request.Filename)

					if err != nil {
						return
					}

					responseLength := uint32(len(fileContent))

					err = binary.Write(conn, binary.BigEndian, responseLength)

					if err != nil {
						return
					}

					err = binary.Write(conn, binary.BigEndian, fileContent)

					if err != nil {
						return
					}
				}
			}()
		}
	}()

	return transform, nil
}

type LoadFileRequest struct {
	Context  string `json:"context"`
	Filename string `json:"filename"`
}

func loadFileFromContext(ctx context.Context, c client.Client, localCtx string, filename string) ([]byte, error) {
	src := llb.Local(
		localCtx,
		llb.IncludePatterns([]string{filename}),
		llb.SessionID(c.BuildOpts().SessionID),
		llb.SharedKeyHint("@"+localCtx+"/"+filename),
	)

	def, err := src.Marshal(ctx)

	if err != nil {
		return nil, errors.Wrapf(err, "failed to marshal local source")
	}

	res, err := c.Solve(ctx, client.SolveRequest{
		Definition: def.ToPB(),
		Evaluate:   false,
	})

	if err != nil {
		return nil, errors.Wrapf(err, "failed to create solve request")
	}

	ref, err := res.SingleRef()

	if err != nil {
		return nil, err
	}

	var xdockerfile []byte

	readContext := &ReadFileForTwigContext{Parent: ctx}
	xdockerfile, err = ref.ReadFile(readContext, client.ReadRequest{
		Filename: filename,
	})

	if err != nil {
		return nil, fmt.Errorf("failed to read dockerfile '%s': %s\n", filename, err)
	}

	return xdockerfile, nil
}

type ReadFileForTwigContext struct {
	Parent context.Context
}

func (rffc *ReadFileForTwigContext) Deadline() (deadline time.Time, ok bool) {
	return rffc.Parent.Deadline()
}

func (rffc *ReadFileForTwigContext) Done() <-chan struct{} {
	return rffc.Parent.Done()
}

func (rffc *ReadFileForTwigContext) Err() error {
	return rffc.Parent.Err()
}

func (rffc *ReadFileForTwigContext) Value(key interface{}) interface{} {
	if key == "isReadForTwig" {
		return true
	}

	return rffc.Parent.Value(key)
}
