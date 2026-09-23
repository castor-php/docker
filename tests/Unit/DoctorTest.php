<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use Castor\Docker\Doctor\Check;
use Castor\Docker\Doctor\Doctor;
use Castor\Docker\Doctor\Status;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_router_name;

/**
 * "docker:doctor" puts a name on what keeps a project from working, and says
 * how to fix it: the failures the troubleshooting page describes, each one
 * detected on a machine that has it.
 *
 * The machine is a FakeSystemProbe, healthy until a test breaks it.
 */
final class DoctorTest extends TestCase
{
    private FakeSystemProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new FakeSystemProbe();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function doctor(array $data = []): Doctor
    {
        return new Doctor(new Context(
            data: $data + ['project_name' => 'myproject', 'root_domain' => 'myproject.test', 'user_id' => 1000],
            workingDirectory: '/project',
        ), $this->probe, '0.8.0');
    }

    /**
     * @param list<Check> $checks
     */
    private static function check(array $checks, string $label): Check
    {
        foreach ($checks as $check) {
            if ($check->label === $label) {
                return $check;
            }
        }

        static::fail(\sprintf('No "%s" check in: %s.', $label, implode(', ', array_map(static fn(Check $check): string => $check->label, $checks))));
    }

    /**
     * @param list<Check> $checks
     */
    private static function assertCheck(Status $status, string $label, array $checks, ?string $result = null, ?string $fix = null): void
    {
        $check = self::check($checks, $label);

        static::assertSame($status, $check->status, $label . ': ' . $check->result);

        if (null !== $result) {
            static::assertStringContainsString($result, $check->result);
        }

        if (null !== $fix) {
            static::assertStringContainsString($fix, (string) $check->fix);
        }
    }

    public function testAHealthyMachineHasNothingToFix(): void
    {
        foreach ($this->doctor()->run() as $section => $checks) {
            foreach ($checks as $check) {
                static::assertSame(Status::Ok, $check->status, \sprintf('%s › %s: %s', $section, $check->label, $check->result));
                static::assertNull($check->fix);
            }
        }
    }

    public function testEveryProblemSaysHowToFixIt(): void
    {
        $this->probe->docker = ['client' => '28.5.1', 'server' => null, 'platform' => null, 'error' => 'Cannot connect to the Docker daemon'];
        $this->probe->composeVersion = '2.19.0';
        $this->probe->mkcertCaRoot = null;
        $this->probe->resolved = ['app.myproject.test' => []];
        $this->probe->owners['/project'] = 1001;

        foreach ($this->doctor()->run() as $checks) {
            foreach ($checks as $check) {
                if (\in_array($check->status, [Status::Warning, Status::Error], true)) {
                    static::assertNotEmpty($check->fix, $check->label);
                }
            }
        }
    }

    public function testWithoutDockerTheDependentChecksAreSkipped(): void
    {
        $this->probe->docker = ['client' => null, 'server' => null, 'platform' => null, 'error' => null];

        $docker = $this->doctor()->checkDocker();

        self::assertCheck(Status::Error, 'Docker', $docker, 'not installed', 'https://docs.docker.com/engine/install/');
        self::assertCheck(Status::Skipped, 'Compose', $docker);
        self::assertCheck(Status::Skipped, 'Buildx', $docker);
        self::assertCheck(Status::Skipped, 'Disk space', $docker);
    }

    /**
     * The daemon is the one cause to report: what needs it is skipped, and what
     * does not — HTTPS, DNS, permissions — still answers.
     */
    public function testWithoutADaemonTheRestStillAnswers(): void
    {
        $this->probe->docker = ['client' => '28.5.1', 'server' => null, 'platform' => null, 'error' => "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n"];

        $report = $this->doctor()->run();

        self::assertCheck(Status::Error, 'Docker', $report['Docker'], 'Is the docker daemon running?', 'sudo systemctl start docker');
        self::assertCheck(Status::Ok, 'Compose', $report['Docker']);
        self::assertCheck(Status::Skipped, 'Disk space', $report['Docker']);
        self::assertCheck(Status::Skipped, 'Containers', $report['Project']);
        self::assertCheck(Status::Skipped, 'Router', $report['Router']);
        self::assertCheck(Status::Ok, 'Port 80', $report['Ports'], 'Free.');
        self::assertCheck(Status::Ok, 'mkcert', $report['HTTPS']);
        self::assertCheck(Status::Ok, 'This machine', $report['DNS']);
        self::assertCheck(Status::Ok, 'User', $report['Project']);
    }

    public function testADaemonUnreachableFromWslPointsAtDockerDesktop(): void
    {
        $this->probe->platform = 'wsl';
        $this->probe->docker = ['client' => '28.5.1', 'server' => null, 'platform' => null, 'error' => 'failed to connect to the docker API at unix:///var/run/docker.sock'];

        self::assertCheck(Status::Error, 'Docker', $this->doctor()->checkDocker(), fix: 'WSL integration');
    }

    public function testADaemonRefusingTheUserSaysToJoinTheDockerGroup(): void
    {
        $this->probe->docker = ['client' => '28.5.1', 'server' => null, 'platform' => null, 'error' => 'permission denied while trying to connect to the docker API at unix:///var/run/docker.sock'];

        self::assertCheck(Status::Error, 'Docker', $this->doctor()->checkDocker(), 'refuses this user', 'usermod -aG docker');
    }

    /**
     * The compose.yaml of every project includes the generated file.
     */
    public function testComposeMustUnderstandInclude(): void
    {
        $this->probe->composeVersion = '2.19.1';

        self::assertCheck(Status::Error, 'Compose', $this->doctor()->checkDocker(), '"include:" needs Compose 2.20.0');
    }

    /**
     * The compose file of the router inlines its Caddyfile, which "include:"
     * alone does not guarantee.
     */
    public function testComposeMustUnderstandTheRouterComposeFile(): void
    {
        $this->probe->composeVersion = '2.21.0';

        self::assertCheck(Status::Error, 'Compose', $this->doctor()->checkDocker(), 'needs Compose 2.23.1');
    }

    public function testAMissingComposePluginIsAnError(): void
    {
        $this->probe->composeVersion = null;

        $report = $this->doctor()->run();

        self::assertCheck(Status::Error, 'Compose', $report['Docker'], 'not installed');
        self::assertCheck(Status::Skipped, 'Compose files', $report['Project']);
    }

    /**
     * Compose 2.40.2 builds through Buildx and refuses one older than 0.17:
     * without it, "docker:build" itself fails.
     */
    public function testARecentComposeCannotBuildWithoutBuildx(): void
    {
        $this->probe->buildxVersion = null;
        self::assertCheck(Status::Error, 'Buildx', $this->doctor()->checkDocker(), 'docker:build fails');

        $this->probe->buildxVersion = '0.16.2';
        self::assertCheck(Status::Error, 'Buildx', $this->doctor()->checkDocker(), 'Buildx 0.16.2 is too old');
    }

    /**
     * An older Compose builds on its own: only "docker:push" needs Buildx then.
     */
    public function testAnOlderComposeOnlyNeedsBuildxToPush(): void
    {
        $this->probe->composeVersion = '2.39.1';
        $this->probe->buildxVersion = null;
        self::assertCheck(Status::Warning, 'Buildx', $this->doctor()->checkDocker(), 'docker:push');

        $this->probe->buildxVersion = '0.12.0';
        self::assertCheck(Status::Ok, 'Buildx', $this->doctor()->checkDocker());
    }

    /**
     * The "docker" driver exports a cache to a registry with the containerd
     * image store only — which only matters to a project pushing one.
     */
    public function testPushingTheBuildCacheNeedsABuilderThatCanExportIt(): void
    {
        self::assertCheck(Status::Ok, 'Buildx', $this->doctor()->checkDocker());

        $registry = ['registry' => 'ghcr.io/org/project'];
        self::assertCheck(Status::Warning, 'Buildx', $this->doctor($registry)->checkDocker(), '"docker" driver', 'docker buildx create --use --driver docker-container');

        $this->probe->dockerInfo = ['containerdStore' => true] + (array) $this->probe->dockerInfo;
        self::assertCheck(Status::Ok, 'Buildx', $this->doctor($registry)->checkDocker());

        $this->probe->dockerInfo = ['containerdStore' => false] + $this->probe->dockerInfo;
        $this->probe->buildxDriver = 'docker-container';
        self::assertCheck(Status::Ok, 'Buildx', $this->doctor($registry)->checkDocker());
    }

    public function testTheDiskOfTheDaemonMustNotBeFull(): void
    {
        $this->probe->freeDiskSpace = 1_500_000_000;
        self::assertCheck(Status::Error, 'Disk space', $this->doctor()->checkDocker(), '1.5GB free in /var/lib/docker', 'docker system prune');

        $this->probe->freeDiskSpace = 6_000_000_000;
        self::assertCheck(Status::Warning, 'Disk space', $this->doctor()->checkDocker(), '6GB free');
    }

    /**
     * Docker Desktop, Colima or a remote host: the disk is not one of this
     * machine, whatever path the daemon names.
     */
    public function testTheDiskOfADaemonInAVmIsNotMeasured(): void
    {
        $this->probe->dockerInfo = ['name' => 'docker-desktop', 'os' => 'Docker Desktop', 'rootDir' => '/var/lib/docker', 'containerdStore' => true];
        $this->probe->freeDiskSpace = 1;

        self::assertCheck(Status::Skipped, 'Disk space', $this->doctor()->checkDocker(), 'docker-desktop');
    }

    public function testComposeFilesDockerRejects(): void
    {
        $this->probe->composeConfigError = "validating compose.override.yaml: services.app additional properties 'restartt' not allowed";

        self::assertCheck(Status::Error, 'Compose files', $this->doctor()->checkProject(), "'restartt' not allowed", 'compose.override.yaml');
    }

    /**
     * "Containers will not start", in the troubleshooting page.
     */
    public function testContainersThatDoNotStartPointAtTheirLogs(): void
    {
        $this->probe->projectContainers = [
            $this->probe->container('app', 'restarting', 'Restarting (1) 3 seconds ago'),
            $this->probe->container('worker', 'running', 'Up 2 minutes (unhealthy)'),
            $this->probe->container('postgres', 'exited', 'Exited (1) 1 minute ago'),
        ];

        $checks = $this->doctor()->checkProject();
        $containers = array_values(array_filter($checks, static fn(Check $check): bool => 'Containers' === $check->label));

        static::assertCount(3, $containers);
        static::assertSame(Status::Error, $containers[0]->status);
        static::assertSame('castor docker:logs app', substr((string) $containers[0]->fix, -\strlen('castor docker:logs app')));
        static::assertSame(Status::Warning, $containers[1]->status);
        static::assertStringContainsString('worker is unhealthy', $containers[1]->result);
        static::assertSame(Status::Warning, $containers[2]->status);
        static::assertStringContainsString('postgres exited with code 1', $containers[2]->result);
    }

    /**
     * "docker:stop" ends a container with a signal: that is no crash.
     */
    public function testAStoppedContainerIsNoProblem(): void
    {
        $this->probe->projectContainers = [
            $this->probe->container('app', 'exited', 'Exited (137) 1 minute ago'),
            $this->probe->container('worker', 'exited', 'Exited (0) 1 minute ago'),
            $this->probe->container('app-builder', 'exited', 'Exited (2) 1 minute ago', oneOff: true),
        ];

        self::assertCheck(Status::Ok, 'Containers', $this->doctor()->checkProject(), '0 running, 3 stopped.');
    }

    public function testRunningCastorAsRootIsAWarning(): void
    {
        $this->probe->currentUserId = 0;

        self::assertCheck(Status::Warning, 'User', $this->doctor(['user_id' => 0])->checkProject(), 'castor runs as root', 'without sudo');
    }

    public function testTheContainersShouldRunAsTheOwnerOfTheProject(): void
    {
        $this->probe->owners['/project'] = 1001;

        self::assertCheck(Status::Warning, 'User', $this->doctor()->checkProject(), 'belongs to uid 1001', "'user_id' => 1001");
    }

    /**
     * "Permission issues", in the troubleshooting page: a directory docker
     * created as root.
     */
    public function testAMountTheContainersCannotWriteIn(): void
    {
        $this->probe->owners['/project/.home'] = 0;
        $this->probe->unwritable = ['/project/.home'];

        self::assertCheck(Status::Error, 'Mounts', $this->doctor()->checkProject(), '.home is not writable', 'sudo chown -R $(id -u):$(id -g) .home');
    }

    public function testAMountOwnedBySomeoneElse(): void
    {
        $this->probe->owners['/project/.home'] = 1001;

        self::assertCheck(Status::Warning, 'Mounts', $this->doctor()->checkProject(), '.home belongs to uid 1001');
    }

    /**
     * A missing directory is created by the next castor run, as the right user.
     */
    public function testAMissingMountIsNoProblem(): void
    {
        unset($this->probe->owners['/project/.home']);

        self::assertCheck(Status::Ok, 'Mounts', $this->doctor()->checkProject());
    }

    public function testARouterStoppedWhileTheProjectRunsIsAnError(): void
    {
        $this->probe->router = null;

        self::assertCheck(Status::Error, 'Router', $this->doctor()->checkRouter(), 'none of its URLs answers', 'castor docker:router:enable');
    }

    public function testARouterStoppedWithTheProjectIsFine(): void
    {
        $this->probe->router = null;
        $this->probe->projectContainers = [];

        self::assertCheck(Status::Ok, 'Router', $this->doctor()->checkRouter(), 'castor docker:up starts it');

        $this->probe->routerAutostart = false;
        self::assertCheck(Status::Warning, 'Router', $this->doctor()->checkRouter(), 'autostart is off');
    }

    public function testAProjectRoutingNoDomainDoesNotNeedTheRouter(): void
    {
        $this->probe->router = null;
        $this->probe->projectUrls = [];

        $this->probe->realPaths = [];
        $router = $this->doctor()->checkRouter();

        self::assertCheck(Status::Ok, 'Router', $router, 'routes no domain');
        self::assertNoCheck('Docker socket', $router);
    }

    /**
     * The router is global: another project may have created it with an older
     * release of the plugin, and a running one is never replaced behind the
     * projects it serves.
     */
    public function testARouterWithAnOlderConfigurationIsReported(): void
    {
        $this->probe->router = ['version' => '0.6.0', 'checksum' => 'older'] + (array) $this->probe->router;

        self::assertCheck(Status::Warning, 'Router', $this->doctor()->checkRouter(), 'created by castor-php/docker 0.6.0', 'castor docker:router:restart');
    }

    /**
     * A router a newer project created is kept as it is.
     */
    public function testARouterWithANewerConfigurationIsFine(): void
    {
        $this->probe->router = ['version' => 'dev-next', 'checksum' => 'newer'] + (array) $this->probe->router;

        self::assertCheck(Status::Ok, 'Router', $this->doctor()->checkRouter());
    }

    /**
     * "The router does not route", step 2: it joins a project network on
     * "docker:up", so a project started while it was down is not reachable.
     */
    public function testARouterThatDidNotJoinTheProjectNetwork(): void
    {
        $this->probe->router = ['networks' => [get_router_name() . '_default']] + (array) $this->probe->router;

        self::assertCheck(Status::Error, 'Network', $this->doctor()->checkRouter(), 'has not joined myproject_default', 'castor docker:router:enable');
    }

    /**
     * Watching a socket that does not exist is silent: the router comes up,
     * sees no label, and serves nothing.
     */
    public function testARouterWatchingNoSocket(): void
    {
        $this->probe->router = null;
        $this->probe->projectContainers = [];
        $this->probe->dockerEndpoint = 'unix:///run/user/1000/docker.sock';
        $this->probe->realPaths = ['/run/user/1000/docker.sock' => '/run/user/1000/docker.sock'];

        self::assertCheck(Status::Error, 'Docker socket', $this->doctor()->checkRouter(), 'does not exist', 'export DOCKER_SOCKET_PATH=/run/user/1000/docker.sock');
    }

    /**
     * A rootless daemon beside a rootful one: /var/run/docker.sock exists, but
     * belongs to the daemon the projects do not run on.
     */
    public function testARouterWatchingAnotherDaemon(): void
    {
        $this->probe->dockerEndpoint = 'unix:///run/user/1000/docker.sock';
        $this->probe->realPaths['/run/user/1000/docker.sock'] = '/run/user/1000/docker.sock';

        self::assertCheck(Status::Warning, 'Docker socket', $this->doctor()->checkRouter(), 'the router sees the containers of another daemon', 'DOCKER_SOCKET_PATH=/run/user/1000/docker.sock');
    }

    public function testARouterEnabledOnAnotherSocketThanTheOneOfThisShell(): void
    {
        $this->probe->routerSocketPath = '/run/user/1000/docker.sock';

        self::assertCheck(Status::Warning, 'Docker socket', $this->doctor()->checkRouter(), 'The router watches /var/run/docker.sock, but castor would now give it /run/user/1000/docker.sock', 'castor docker:router:enable');
    }

    /**
     * Docker Desktop and Colima resolve /var/run/docker.sock in their VM, where
     * it is always theirs: this machine has nothing to say about it.
     */
    public function testTheSocketOfADaemonInAVmIsNotLookedUpHere(): void
    {
        $this->probe->dockerInfo = ['name' => 'docker-desktop', 'os' => 'Docker Desktop', 'rootDir' => '/var/lib/docker', 'containerdStore' => true];
        $this->probe->dockerEndpoint = 'unix:///home/me/.docker/desktop/docker.sock';
        $this->probe->realPaths = [];

        self::assertCheck(Status::Ok, 'Docker socket', $this->doctor()->checkRouter());
    }

    /**
     * "Port conflicts", in the troubleshooting page.
     */
    public function testAContainerHoldingThePortsOfTheRouter(): void
    {
        $this->probe->router = null;
        $this->probe->portContainers[80] = ['name' => 'legacy-nginx-1', 'project' => 'legacy'];

        self::assertCheck(Status::Error, 'Port 80', $this->doctor()->checkPorts(), 'Held by the container legacy-nginx-1, of the legacy project: the router cannot bind it.', 'docker stop legacy-nginx-1');
    }

    public function testAProcessHoldingThePortsOfTheRouter(): void
    {
        $this->probe->router = null;
        $this->probe->portContainers = [];
        $this->probe->portListeners = [80 => 'nginx (pid 812)', 443 => ''];

        $ports = $this->doctor()->checkPorts();

        self::assertCheck(Status::Error, 'Port 80', $ports, 'Held by nginx (pid 812)', 'Stop nginx (pid 812).');
        self::assertCheck(Status::Error, 'Port 443', $ports, 'a process castor cannot name', "sudo ss -ltnp 'sport = :443'");
    }

    /**
     * Without the daemon, a listener may well be the router itself.
     */
    public function testAListenerIsOnlyAWarningWithoutTheDaemon(): void
    {
        $this->probe->docker = ['client' => '28.5.1', 'server' => null, 'platform' => null, 'error' => 'permission denied'];
        $this->probe->portListeners = [80 => ''];

        self::assertCheck(Status::Warning, 'Port 80', $this->doctor()->checkPorts(), 'castor cannot tell without the Docker daemon');
    }

    public function testThePortsTheProjectPublishes(): void
    {
        $this->probe->projectPublishedPorts = ['postgres' => ['5432'], 'mailpit' => ['1025'], 'app' => ['8000-8010']];
        $this->probe->portContainers[5432] = ['name' => 'myproject-postgres-1', 'project' => 'myproject'];
        $this->probe->portListeners[1025] = 'python3 (pid 42)';

        $ports = $this->doctor()->checkPorts();

        self::assertCheck(Status::Ok, 'Port 5432', $ports, 'Published by myproject-postgres-1.');
        self::assertCheck(Status::Error, 'Port 1025', $ports, 'mailpit cannot publish it');
        static::assertCount(4, $ports, 'A range is not looked up.');
    }

    /**
     * Two checkouts of the same repository publishing the same port: the one
     * started second cannot come up.
     */
    public function testAPortHeldByAnotherCheckout(): void
    {
        $this->probe->projectPublishedPorts = ['postgres' => ['5432']];
        $this->probe->portContainers[5432] = ['name' => 'myproject-postgres-1', 'project' => 'myproject'];

        self::assertCheck(Status::Error, 'Port 5432', $this->doctor(['project_name' => 'myproject-bug-4242'])->checkPorts(), 'of the myproject project', 'or publish postgres on another port');
    }

    public function testAProjectRoutingNoDomainNeedsNeither80Nor443(): void
    {
        $this->probe->projectUrls = [];

        static::assertSame([Status::Ok], array_map(static fn(Check $check): Status => $check->status, $this->doctor()->checkPorts()));
    }

    /**
     * "Certificate warnings in the browser", in the troubleshooting page.
     */
    public function testWithoutMkcertTheBrowserWarns(): void
    {
        $this->probe->mkcertCaRoot = null;

        self::assertCheck(Status::Warning, 'mkcert', $this->doctor()->checkHttps(), 'not installed', 'mkcert -install');
    }

    public function testMkcertWithoutItsCa(): void
    {
        $this->probe->hashes = [];

        self::assertCheck(Status::Warning, 'mkcert', $this->doctor()->checkHttps(), 'has no CA', 'mkcert -install');
    }

    /**
     * The Caddyfile imports the CA when the router starts: a running one has to
     * restart to use a new copy.
     */
    public function testARouterWithoutTheMkcertCa(): void
    {
        $this->probe->hashes = ['/home/me/.local/share/mkcert/rootCA.pem' => 'mkcert-ca'];

        self::assertCheck(Status::Warning, 'Router CA', $this->doctor()->checkHttps(), 'no copy of the mkcert CA', 'castor docker:router:restart');

        $this->probe->router = null;
        self::assertCheck(Status::Warning, 'Router CA', $this->doctor()->checkHttps(), fix: 'castor docker:router:enable');
    }

    public function testARouterSigningWithAnOlderMkcertCa(): void
    {
        $this->probe->hashes['/home/me/.local/share/mkcert/rootCA.pem'] = 'regenerated-ca';

        self::assertCheck(Status::Warning, 'Router CA', $this->doctor()->checkHttps(), 'another mkcert CA');
    }

    public function testACaTheSystemDoesNotTrust(): void
    {
        $this->probe->trustedBySystem = false;

        self::assertCheck(Status::Warning, 'System trust', $this->doctor()->checkHttps(), fix: 'mkcert -install');

        $this->probe->trustedBySystem = null;
        self::assertCheck(Status::Skipped, 'System trust', $this->doctor()->checkHttps());
    }

    /**
     * mkcert, run in the distribution, never touches the store of Windows —
     * where the browser of a WSL user runs.
     */
    public function testUnderWslWindowsHasToTrustTheCaToo(): void
    {
        self::assertNoCheck('Windows trust', $this->doctor()->checkHttps());

        $this->probe->platform = 'wsl';
        self::assertCheck(Status::Ok, 'Windows trust', $this->doctor()->checkHttps());

        $this->probe->trustedByWindows = false;
        self::assertCheck(Status::Warning, 'Windows trust', $this->doctor()->checkHttps(), fix: 'certutil.exe -user -addstore Root');
    }

    public function testAProjectRoutingNoDomainNeedsNoCertificate(): void
    {
        $this->probe->projectUrls = [];

        self::assertCheck(Status::Skipped, 'HTTPS', $this->doctor()->checkHttps());
        self::assertCheck(Status::Skipped, 'DNS', $this->doctor()->checkDns());
    }

    /**
     * "The router does not route", step 3.
     */
    public function testADomainThatDoesNotResolve(): void
    {
        $this->probe->resolved = ['app.myproject.test' => []];

        self::assertCheck(Status::Error, 'This machine', $this->doctor()->checkDns(), 'app.myproject.test: no such host.', 'Add this line to /etc/hosts: 127.0.0.1 app.myproject.test');
    }

    public function testADomainResolvingElsewhere(): void
    {
        $this->probe->resolved = ['myproject.test' => ['93.184.215.14']];

        self::assertCheck(Status::Warning, 'This machine', $this->doctor()->checkDns(), 'myproject.test resolves to 93.184.215.14', '127.0.0.1 myproject.test');
    }

    public function testEveryLoopbackAddressIsThisMachine(): void
    {
        $this->probe->resolved = ['app.myproject.test' => ['::1'], 'myproject.test' => ['127.0.1.1']];

        self::assertCheck(Status::Ok, 'This machine', $this->doctor()->checkDns(), 'The 2 domains resolve to this machine.');
    }

    /**
     * Every "*.localhost" goes to the loopback by itself (RFC 6761), and the
     * domains are listed once whatever their scheme.
     */
    public function testLocalhostDomainsAreNotLookedUp(): void
    {
        $this->probe->projectUrls = ['app' => ['https://localhost', 'https://app.localhost', 'https://myproject.test', 'http://myproject.test', 'https://*.myproject.test']];

        self::assertCheck(Status::Ok, 'This machine', $this->doctor()->checkDns(), 'myproject.test resolves to this machine.');
        static::assertSame(['myproject.test'], $this->probe->lookups);
    }

    /**
     * WSL resolves with its own /etc/hosts, the browser on Windows with the
     * hosts file of Windows.
     */
    public function testUnderWslWindowsHasToResolveTheDomainsToo(): void
    {
        self::assertNoCheck('Windows', $this->doctor()->checkDns());

        $this->probe->platform = 'wsl';
        $this->probe->resolvedOnWindows = ['app.myproject.test' => []];

        self::assertCheck(Status::Error, 'Windows', $this->doctor()->checkDns(), 'app.myproject.test: no such host.', 'C:\Windows\System32\drivers\etc\hosts');

        $this->probe->resolvedOnWindows = null;
        self::assertCheck(Status::Skipped, 'Windows', $this->doctor()->checkDns());
    }

    public function testTheWorktreeSectionOnlyShowsInAWorktree(): void
    {
        static::assertArrayNotHasKey('Worktree', $this->doctor()->run());

        $report = $this->doctor($this->worktree())->run();

        static::assertArrayHasKey('Worktree', $report);
        self::assertCheck(Status::Ok, 'Checkout', $report['Worktree'], 'The bug-4242 worktree, a stack of its own: the myproject-bug-4242 compose project, served under bug-4242.myproject.test.');
    }

    /**
     * What a worktree still shares with the other checkouts, which
     * "docker:about" reports too.
     */
    public function testWhatAWorktreeSharesWithTheOtherCheckouts(): void
    {
        $this->probe->projectUrls = ['app' => ['https://app.bug-4242.myproject.test', 'https://api.partner.test']];
        $this->probe->projectPublishedPorts = ['postgres' => ['5432']];

        $checks = $this->doctor($this->worktree())->checkWorktree();

        self::assertCheck(Status::Warning, 'Domains', $checks, 'api.partner.test: not under bug-4242.myproject.test');
        self::assertCheck(Status::Warning, 'Host ports', $checks, '5432 (postgres)', 'castor <service>:expose <port>');
    }

    /**
     * @return array<string, mixed>
     */
    private function worktree(): array
    {
        return ['worktree' => 'bug-4242', 'project_name' => 'myproject-bug-4242', 'root_domain' => 'bug-4242.myproject.test'];
    }

    /**
     * @param list<Check> $checks
     */
    private static function assertNoCheck(string $label, array $checks): void
    {
        static::assertNotContains($label, array_map(static fn(Check $check): string => $check->label, $checks));
    }
}
