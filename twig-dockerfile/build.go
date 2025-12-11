package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os/exec"

	dockerfile "github.com/moby/buildkit/frontend/dockerfile/builder"
	"github.com/moby/buildkit/frontend/gateway/client"
)

func build(ctx context.Context, c client.Client) (*client.Result, error) {
	// transform opts into json
	jsonString, err := json.Marshal(c.BuildOpts().Opts)

	if err != nil {
		return nil, err
	}

	transform := func(dockerfile []byte) ([]byte, error) {
		cmd := exec.CommandContext(ctx, "castor", "transform-docker-file", string(jsonString))
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
	if err := InjectDockerfileTransform(transform, c); err != nil {
		return nil, err
	}

	// Pass control to the upstream Dockerfile frontend
	return dockerfile.Build(ctx, c)
}
