<?php

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;

class TraefikRouterService implements ServiceInterface
{
    public function __construct(
        protected string $sharedHomeDirectory = '.home',
        protected string $rootDomain = 'local.castor',
        /** @var string[] */
        protected array $extraDomains = [],
    ) {
    }

    public function addExtraDomain(string $domain): self
    {
        $this->extraDomains[] = $domain;
        return $this;
    }

    public function getName(): string
    {
        return 'router';
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $userId = $context->data['user_id'] ?? 1000;

        $compose['services'][$this->getName()] = [
            'build' => __DIR__ . '/../Resources/router',
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock',
                $this->sharedHomeDirectory . ":/home/app:cached",
            ],
            'ports' => [
                '80:80',
                '443:443',
                '8080:8080',
            ],
            'networks' => [
                'default',
            ],
            'profiles' => [
                'default',
            ],
        ];

        return $compose;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('generate-certificates', 'router', description: 'Generates SSL certificates (with mkcert if available or self-signed if not)'),
            'function' => function (bool $force = false) {
                $this->generateCertificates($force);
            },
        ];
    }

    private function generateCertificates(bool $force = false): void
    {
        $sslDir = $this->sharedHomeDirectory . '/certs';

        if (file_exists("{$sslDir}/cert.pem") && !$force) {
            io()->comment('SSL certificates already exists.');
            io()->note('Run "castor docker:generate-certificates --force" to generate new certificates.');

            return;
        }

        // create directory if not exists
        if (!is_dir($sslDir)) {
            fs()->mkdir($sslDir);
        }

        io()->title('Generating SSL certificates');

        if ($force) {
            if (file_exists($f = "{$sslDir}/cert.pem")) {
                io()->comment('Removing existing certificates in infrastructure/docker/services/router/certs/*.pem.');
                unlink($f);
            }

            if (file_exists($f = "{$sslDir}/key.pem")) {
                unlink($f);
            }
        }

        $finder = new ExecutableFinder();
        $mkcert = $finder->find('mkcert');

        if ($mkcert) {
            $pathCaRoot = capture(['mkcert', '-CAROOT']);

            if (!is_dir($pathCaRoot)) {
                io()->warning('You must have mkcert CA Root installed on your host with "mkcert -install" command.');

                return;
            }

            run([
                'mkcert',
                '-cert-file', "{$sslDir}/cert.pem",
                '-key-file', "{$sslDir}/key.pem",
                $this->rootDomain,
                "*.{$this->rootDomain}",
                ...$this->extraDomains,
            ]);

            io()->success('Successfully generated SSL certificates with mkcert.');

            if ($force) {
                io()->note('Please restart the infrastructure to use the new certificates with "castor up" or "castor start".');
            }

            return;
        }

        run([__DIR__ . '/../Resources/router/generate-ssl.sh'], context: context()->withQuiet());

        io()->success('Successfully generated self-signed SSL certificates in infrastructure/docker/services/router/certs/*.pem.');
        io()->comment('Consider installing mkcert to generate locally trusted SSL certificates and run "castor docker:generate-certificates --force".');

        if ($force) {
            io()->note('Please restart the infrastructure to use the new certificates with "castor up" or "castor start".');
        }
    }
}
