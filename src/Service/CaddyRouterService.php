<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\capture;
use function Castor\Docker\docker_compose;
use function Castor\fs;
use function Castor\get_cache;
use function Castor\io;

class CaddyRouterService implements ServiceInterface
{
    use HasSharedHomeDirectory;

    public function getName(): string
    {
        return 'router';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('router-data')
            ->service($this->getName())
                ->build(__DIR__ . '/../Resources/router')->end()
                // caddy-docker-proxy watches the Docker socket to build its
                // configuration from the "caddy.*" labels of the services.
                ->volume('/var/run/docker.sock', '/var/run/docker.sock')
                // Persist issued certificates and the local CA between restarts.
                ->volume('router-data', '/data')
                // Shared home holds the optional mkcert CA (certs/) used to mint
                // locally-trusted certificates on demand.
                ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                ->port('80', '80')
                ->port('443', '443')
                ->profile('router')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('enable', 'router', description: 'Enable router service'),
            'function' => function (): void {
                $this->installCertificateAuthority();

                $routerCache = get_cache()->getItem('infrastructure.router.enabled');
                $routerCache->set(true);

                get_cache()->save($routerCache);
                docker_compose(['up', '-d', 'router']);
            },
        ];

        yield [
            'task' => new AsTask('disable', 'router', description: 'Disable router service'),
            'function' => function (): void {
                $routerCache = get_cache()->getItem('infrastructure.router.enabled');
                $routerCache->set(false);

                get_cache()->save($routerCache);
                docker_compose(['stop', 'router']);
            },
        ];
    }

    /**
     * Copy the mkcert root CA into the shared home so the router can sign its
     * on-demand certificates with it. Runs on every "router:enable" and is a
     * no-op when mkcert is not available (the router then falls back to its own
     * local CA).
     */
    private function installCertificateAuthority(): void
    {
        $certsDir = $this->getSharedHomeDirectory() . '/certs';
        $caddyDir = $certsDir . '/caddy';

        $mkcert = (new ExecutableFinder())->find('mkcert');

        if (!$mkcert) {
            io()->comment('mkcert is not installed: the router will use its own local CA (browsers will show a security warning). Install mkcert (https://github.com/FiloSottile/mkcert) for locally-trusted certificates.');

            return;
        }

        $caRoot = trim(capture(['mkcert', '-CAROOT']));

        if (!is_dir($caRoot) || !file_exists("{$caRoot}/rootCA.pem") || !file_exists("{$caRoot}/rootCA-key.pem")) {
            io()->warning('You must have the mkcert CA installed on your host with the "mkcert -install" command.');

            return;
        }

        fs()->mkdir($caddyDir);
        fs()->copy("{$caRoot}/rootCA.pem", "{$certsDir}/rootCA.pem", true);
        fs()->copy("{$caRoot}/rootCA-key.pem", "{$certsDir}/rootCA-key.pem", true);

        // Tell Caddy's internal issuer to sign on-demand certificates with the
        // mkcert root, which is already trusted by the host and its browsers.
        //
        // The mkcert root is created with "pathlen:0", so it may only sign leaf
        // certificates directly, not an intermediate CA. Caddy signs leaves with
        // its intermediate by default, so we point the intermediate at the mkcert
        // root as well: leaves are then signed straight from the mkcert root and
        // the chain stays valid (otherwise browsers reject it with a "path length
        // constraint exceeded" error).
        fs()->dumpFile("{$caddyDir}/ca.caddy", <<<'CADDY'
            pki {
                ca local {
                    root {
                        cert /home/app/certs/rootCA.pem
                        key /home/app/certs/rootCA-key.pem
                    }
                    intermediate {
                        cert /home/app/certs/rootCA.pem
                        key /home/app/certs/rootCA-key.pem
                    }
                }
            }
            CADDY);

        io()->comment('Using the mkcert CA for locally-trusted certificates.');
    }
}
