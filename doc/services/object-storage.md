---
title: S3 object storage
description: An S3-compatible storage with RustFS, its console, and the buckets your application expects.
---

# S3 object storage

`RustFSService` runs [RustFS](https://rustfs.com), an S3-compatible object
storage, with its web console. Declare the buckets the application expects, and
link it:

```php
$rustfs = (new RustFSService())
    ->withBucket('uploads')
    ->withBucket('media', public: true);
$event->addService($rustfs);

$event->addService(
    (new SymfonyService('app'))->withDirectory(__DIR__)->link($rustfs)
);
```

```php
(new RustFSService())
    ->withVersion('1.0.0')                       // Image tag (default: 1.0.0)
    ->withCredentials('access', 'secret')        // default: castor / castor-secret-key
    ->withBucket('uploads')
    ->withBucket('media', public: true)          // anyone may download its objects
    ->withClientVersion('v0.1.36')               // the rc image creating the buckets
```

## Buckets

Nothing in RustFS creates a bucket at startup, so a one-shot container does it:
`rustfs-buckets` runs the RustFS client once the server is ready, and the
applications linked to the storage wait for it to succeed. They start with their
buckets there, on a fresh volume as on an old one — creating a bucket that
exists is not an error.

A bucket is only ever created. One removed from the list stays, with its
objects, until you delete it from the console.

A public bucket lets anyone download its objects without signing the request,
which is what an `<img src>` pointing at an uploaded file needs. The permission
is granted on every start, never taken back: a bucket made private again is made
so from the console, like any policy set there.

## What the application receives

No two ecosystems read the same names, so the application gets all of them:

| Variable | Value | Read by |
|----------|-------|---------|
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | the credentials | the AWS SDK, async-aws, Laravel |
| `AWS_REGION` | `us-east-1` | the AWS SDK, async-aws |
| `AWS_DEFAULT_REGION` | `us-east-1` | Laravel |
| `AWS_ENDPOINT_URL_S3` | `http://rustfs:9000` | the AWS SDK, on its own |
| `AWS_ENDPOINT` | `http://rustfs:9000` | Laravel |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | Laravel |
| `S3_PUBLIC_ENDPOINT` | `https://rustfs.{root_domain}` | the URLs the browser follows |

`AWS_ENDPOINT_URL` — the only endpoint variable async-aws reads — is left out on
purpose: it applies to every AWS client of the application, and would send its
SQS or SES clients to the object storage too. Name the endpoint in the
configuration of the client instead. No SDK reads a variable for the path style
either, so it goes there too — without it, the bucket is looked up as the host
`uploads.rustfs`:

```yaml
# config/packages/async_aws.yaml
async_aws:
    clients:
        s3:
            config:
                endpoint: '%env(AWS_ENDPOINT_URL_S3)%'
                pathStyleEndpoint: '%env(AWS_USE_PATH_STYLE_ENDPOINT)%'
```

The name of the bucket is left to the application: it does not change with the
environment, so it belongs to its `.env` — `AWS_BUCKET` for Laravel.

These are real environment variables, which Symfony and Laravel read before any
`.env` file, `.env.local` included — and the credentials are the ones every AWS
client of the application picks up. An application talking to the real AWS for
something else, SES or SQS, would send these there: do not link the storage to
it, configure its S3 client with names of its own.

### Presigned URLs

A presigned URL is signed for a host, and the one of the project network —
`rustfs:9000` — is not one a browser can reach. Presign with a second client
configured with `S3_PUBLIC_ENDPOINT`: signing happens locally, it needs no access
to that endpoint, and the router forwards the host untouched, so the signature
holds when the browser follows the URL.

For a public bucket, the address of an object is
`{S3_PUBLIC_ENDPOINT}/{bucket}/{key}` — Laravel's `AWS_URL` is that, with the
bucket: `AWS_URL=${S3_PUBLIC_ENDPOINT}/media`.

## The console

The console is served on a domain of its own, since RustFS answers it on a port
of its own: log in with the credentials. The API is on `https://rustfs.{root_domain}`,
for a browser following a URL and for any S3 client trusting the certificates of
the router.

A tool of the host that does not — the AWS CLI and rclone ship their own
certificate bundle — reaches the API over plain HTTP with
`castor rustfs:expose`, on `http://127.0.0.1:9000`.

* **Containers:** `rustfs`, `rustfs-buckets` when buckets are declared, named
  volume `rustfs-data`
* **UI:** `https://rustfs-console.{root_domain}` when the router is enabled
* **Task:** `castor rustfs:expose` — reach the S3 API from the host

The data lives in a named volume: the server runs as a user of its own, and
refuses a directory of the host it cannot write to.
