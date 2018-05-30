<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor;

use Bozoslivehere\SupervisorDaemonBundle\Entity\Daemon;
use Bozoslivehere\SupervisorDaemonBundle\Utils\Utils;
use Doctrine\ORM\EntityManager;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

abstract class SupervisorDaemon
{

    const ONE_MINUTE = 60000000;
    const FIVE_MINUTES = self::ONE_MINUTE * 5;
    const TEN_MINUTES = self::ONE_MINUTE * 10;
    const TWELVE_MINUTES = self::ONE_MINUTE * 12;
    const ONE_HOUR = self::ONE_MINUTE * 60 * 60;
    const ONE_DAY = self::ONE_HOUR * 24;

    const STATUS_UNKOWN = 'UNKNOWN';
    const STATUS_RUNNING = 'RUNNING';
    const STATUS_STOPPED = 'STOPPED';
    const STATUS_FATAL = 'FATAL';
    const STATUS_STARTING = 'STARTING';

    const STATUSES = [
        self::STATUS_UNKOWN,
        self::STATUS_RUNNING,
        self::STATUS_STOPPED,
        self::STATUS_FATAL,
        self::STATUS_STARTING
    ];

    protected $terminated = false;
    protected $torndown = false;
    protected $timeout = self::FIVE_MINUTES;
    protected $options = [];
    protected $maxIterations = 100;
    private $currentIteration = 0;
    protected $paused = false;
    protected $pid;
    private $daemons = [];
    protected $name;
    protected static $extension = '.conf';
    protected $errors = [];
    protected $logLevel;

    protected $shouldCheckin = true;

    protected $autostart = true;

    protected static $sigHandlers = [
        SIGHUP => array(__CLASS__, 'defaultSigHandler'),
        SIGINT => array(__CLASS__, 'defaultSigHandler'),
        SIGUSR1 => array(__CLASS__, 'defaultSigHandler'),
        SIGUSR2 => array(__CLASS__, 'defaultSigHandler'),
        SIGTERM => array(__CLASS__, 'defaultSigHandler')
    ];

    /**
     *
     * @var \Monolog\Logger
     */
    protected $logger = null;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * SupervisorDaemon constructor.
     * @param ContainerInterface $container
     * @param int $logLevel
     */
    public function __construct(ContainerInterface $container, $logLevel = Logger::ERROR)
    {
        $this->setContainer($container);
        $this->pid = getmypid();
        $this->logLevel = $logLevel;
        $this->attachHandlers();
    }

    /**
     * SupervisorDaemon destructor.
     * There's no garantee this will be called, when the daemon is stopped by 'kill -9' for example
     */
    public function __destruct()
    {
        $this->teardown();
    }

    /**
     * Any incoming pcntl signals will be handled here
     * receiving any signal will interrupt usleep
     *
     * @param $signo
     */
    public final function defaultSigHandler($signo)
    {
        switch ($signo) {
            case SIGTERM:
                $this->terminate('Received signal: SIGTERM');
                break;
            case SIGHUP:
                $this->terminate('Received signal: SIGHUP');
                break;
            case SIGINT:
                $this->terminate('Received signal: SIGINT');
                break;
            case SIGUSR1: // reload configs
                $this->logger->info('Received signal: SIGUSR1');
                $this->reloadConfig();
                break;
            case SIGUSR2: // restart
                $this->logger->info('Received signal: SIGUSR2');
                $this->terminate('Received SIGUSR2, terminating');
                break;
        }
    }

    /**
     * Our main eventloop, loops until terminated or maxIterations is reached
     *
     * @param array $options
     */
    public function run($options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->setup();
        while (!$this->terminated) {
            if ($this->paused) {
                $this->logger->addInfo($this->getName() . ' is pauzed, skipping iterate');
            } else {
                try {
                    $this->iterate();
                } catch (\Exception $error) {
                    if (!empty($this->logger)) {
                        $this->logger->addError($error->getMessage(), [$error]);
                    }
                }
                $this->checkin();
            }
            usleep($this->timeout);
            $this->currentIteration++;
            if ($this->currentIteration == $this->maxIterations) {
                $this->terminate('Max iterations reached: ' . $this->maxIterations);
            }
            pcntl_signal_dispatch();
        }
        $this->teardown();
    }

    /**
     * Run only once and quit immediatly
     * Mainly for test purposes
     *
     * @param array $options
     */
    public function runOnce($options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->setup();
        try {
            $this->iterate();
        } catch (\Exception $error) {
            if (!empty($this->logger)) {
                $this->logger->addError($error->getMessage(), [$error]);
            }
        }
        $this->teardown();
    }

    /**
     * Records activity in the db
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    protected function checkin()
    {
        if ($this->shouldCheckin) {
            /** @var EntityManager $manager */
            $manager = $this->getManager();
            /** @var Daemon $daemon */
            $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
                'name' => $this->getName(),
                'host' => gethostname()
            ]);
            if (empty($daemon)) {
                $this->terminate('Daemon not found in database.', 'error');
            } else {
                $now = new \DateTime('now', new \DateTimeZone("UTC"));
                $daemon->setPid(getmypid())->setLastCheckin($now);
                $manager->persist($daemon);
                $manager->flush();
                $manager->clear();
            }
        }
    }

    /**
     * Attaches all signal handlers defined by $sigHandlers and tests for availability
     */
    protected function attachHandlers()
    {
        foreach (self::$sigHandlers as $signal => $handler) {
            if (is_string($signal) || !$signal) {
                if (defined($signal) && ($const = constant($signal))) {
                    self::$sigHandlers[$const] = $handler;
                }
                unset(self::$sigHandlers[$signal]);
            }
        }
        foreach (self::$sigHandlers as $signal => $handler) {
            if (!pcntl_signal($signal, $handler)) {
                $this->logger->info('Could not bind signal: ' . $signal);
            }
        }
    }

    /**
     * Set up logger with rotating file, will rotate daily and saves up to $maxFiles files
     *
     * @return Logger
     */
    protected function initializeLogger($logLevel)
    {
        $logger = new Logger($this->getName() . '_logger');
        $logger->pushHandler(new RotatingFileHandler($this->getLogFilename(), 10, $logLevel, true, 0777));
        $logger->info('Setting up: ' . get_called_class(), ['pid' => $this->pid]);
        return $logger;
    }

    /**
     * Gets an entity manager, will also reconnect if connection was lost somehow
     *
     * @return EntityManager
     */
    protected function getManager()
    {
        /** @var EntityManager $manager */
        $manager = $this->container->get('doctrine.orm.entity_manager');
        if ($manager->getConnection()->ping() === false) {
            $manager->getConnection()->close();
            $manager->getConnection()->connect();
        }
        return $manager;
    }

    /**
     * Sets the service container
     * @param ContainerInterface $container
     * @return SupervisorDaemon
     */
    public function setContainer(ContainerInterface $container): SupervisorDaemon
    {
        $this->container = $container;
        return $this;
    }

    /**
     * TODO: reload config and restart main loop when SIGUSR1 or 2 is received
     */
    protected function reloadConfig()
    {
    }

    /**
     * Will be called before main loop starts
     */
    protected function setup()
    {
    }

    /**
     * Must be implemented by extenders.
     * Will be called every $timeout microseconds.
     *
     * @return mixed
     */
    abstract protected function iterate();

    /**
     * @return string service id for this daemon
     */
    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
        $this->logger = $this->initializeLogger($this->logLevel);
    }

    /**
     * Runs when maxIterations is reached or when stop signal is receaved.
     *
     * CAREFULL!!! teardown() might not get called on __destroy() :( (kill -9).
     * Please don't do anything important here..
     */
    protected function teardown()
    {
        if (!$this->torndown) {
            $this->logger->info('Torn down', ['pid' => $this->pid]);
            $this->torndown = true;
        }
    }

    /**
     * Terminates main loop and logs a message if present
     *
     * @param $message
     * @param string $state either 'info', 'debug' or 'error'
     */
    public function terminate($message, $state = 'info')
    {
        if (!empty($message)) {
            switch ($state) {
                case 'info':
                    $this->logger->info($message, ['pid' => $this->pid]);
                    break;
                case 'debug':
                    $this->logger->debug($message, ['pid' => $this->pid]);
                    break;
                case 'error':
                    $this->logger->error($message, ['pid' => $this->pid]);
                    break;
            }
        }
        $this->terminated = true;
    }

    /**
     * Gets the symfony log directory appended with hostname for use on shared network drives used by load balanced servers
     *
     * @return string
     */
    protected function getLogDir()
    {
        $logFileDir =
            $this->container->get('kernel')->getLogDir() .
            DIRECTORY_SEPARATOR . 'daemons' . DIRECTORY_SEPARATOR .
            $this->cleanHostName(gethostname()) . DIRECTORY_SEPARATOR;
        if (!is_dir($logFileDir)) {
            mkdir($logFileDir, 0777, true);
        }
        return $logFileDir;
    }

    /**
     * Gets the full log filename
     * @return string
     */
    protected function getLogFilename()
    {
        $logFilename = $this->getLogDir() . $this->getName() . '.log';
        return $logFilename;
    }

    //======================================= management functions =================================

    /**
     * Gets the full name of the supervisor configuration file
     *
     * @return string
     */
    public function getConfName()
    {
        return '/etc/supervisor/conf.d/' . $this->getName() . static::$extension;
    }

    /**
     * Parses output of a supervisor shell command and returns the status as reported by supervisor
     *
     * @param $output
     * @return string
     */
    private function parseStatus($output)
    {
        $output = explode("\n", $output);
        $status = static::STATUS_UNKOWN;
        if (!empty($output[0])) {
            $parts = preg_split('/\s+/', $output[0]);
            if (!empty($parts[1])) {
                if ($parts[1]) {
                    if (in_array($parts[1], static::STATUSES)) {
                        $status = $parts[1];
                    }
                }
            }
        }
        return $status;
    }

    /**
     * Returns the status as reported by supervisor
     *
     * @return string
     */
    public function getStatus()
    {
        $shell = new Process('supervisorctl status ' . $this->getName());
        $shell->run();
        return $this->parseStatus($shell->getOutput());
    }

    /**
     * Build a supervisor config file from a template
     *
     * @param $baseDir
     * @param $supervisorLogDir
     * @return bool|mixed|string
     */
    protected function buildConf($baseDir, $supervisorLogDir)
    {
        $conf = file_get_contents(__DIR__ . '/confs/template' . static::$extension);
        $conf = str_replace('{binDir}', $baseDir . '/bin', $conf);
        $conf = str_replace('{daemonName}', $this->getName(), $conf);
        $logFile = $supervisorLogDir . $this->getName() . '.log';
        $conf = str_replace('{logFile}', $logFile, $conf);
        $conf = str_replace('{autostart}', ($this->autostart) ? 'true' : 'false', $conf);
        $env = $this->container->get('kernel')->getEnvironment();
        $conf = str_replace('{env}', $env, $conf);
        return $conf;
    }

    /**
     * Builds our configuration file and copies it to /etc/supervisor/conf.d and
     * adds us to the daemons table
     *
     * @param ContainerInterface $container
     * @param bool $uninstallFirst
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function install(ContainerInterface $container, $uninstallFirst = false)
    {
        $baseDir = $container->get('kernel')->getRootDir() . '/..';
        $supervisorLogDir = $container->get('kernel')->getLogDir() .
            DIRECTORY_SEPARATOR . 'daemons' . DIRECTORY_SEPARATOR .
            $this->cleanHostName(gethostname()) . DIRECTORY_SEPARATOR .
            'supervisor' . DIRECTORY_SEPARATOR;
        if (!is_dir($supervisorLogDir)) {
            mkdir($supervisorLogDir, 0777, true);
        }
        $conf = $this->buildConf($baseDir, $supervisorLogDir);
        $destination = $this->getConfName();
        if ($uninstallFirst && $this->isInstalled()) {
            $this->uninstall($container);
        }
        if (file_put_contents($destination, $conf) === false) {
            $this->error('Conf could not be copied to ' . $destination);
            return false;
        }
        $this->reload();
        if ($this->shouldCheckin) {
            /** @var EntityManager $manager */
            $manager = $container->get('doctrine.orm.entity_manager');
            /** @var Daemon $daemon */
            $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
                'name' => $this->getName(),
                'host' => gethostname()
            ]);
            if (empty($daemon)) {
                $now = new \DateTime('now', new \DateTimeZone("UTC"));
                $daemon = new Daemon();
                $daemon
                    ->setName($this->getName())
                    ->setHost(gethostname())
                    ->setLastCheckin($now);
                $manager->persist($daemon);
                $manager->flush();
            }
        }

        return $this->isInstalled();
    }

    /**
     * Removes us from the daemons table and deletes the configuration file
     *
     * @param ContainerInterface $container
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function uninstall(ContainerInterface $container)
    {
        $this->stop();
        $conf = $this->getConfName();
        if (!unlink($conf)) {
            $this->error($conf . ' could not be deleted');
            return false;
        }
        $this->reload();
        /** @var EntityManager $manager */
        $manager = $container->get('doctrine.orm.entity_manager');
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
            'name' => $this->getName(),
            'host' => gethostname()
        ]);
        if (!empty($daemon)) {
            $manager->remove($daemon);
            $manager->flush();
        }
        return true;
    }

    /**
     * Retrieves our process id from the daemons table
     *
     * @param ContainerInterface $container
     * @return int
     */
    public function getPid(ContainerInterface $container)
    {
        $pid = 0;
        /** @var EntityManager $manager */
        $manager = $container->get('doctrine.orm.entity_manager');
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
            'name' => $this->getName(),
            'host' => gethostname()
        ]);
        if (!empty($daemon)) {
            $pid = $daemon->getPid();
        }
        return $pid;
    }

    /**
     * Tests if we are getting ready to run
     * @return bool
     */
    public function isStarting()
    {
        return $this->getStatus() == static::STATUS_STARTING;
    }

    /**
     * Tests if we are up and running
     * @return bool
     */
    public function isRunning()
    {
        return $this->getStatus() == static::STATUS_RUNNING;
    }

    /**
     * Tests if we are up and running or getting ready to rock
     * @return bool
     */
    public function isRunningOrStarting()
    {
        $status = $this->getStatus();
        return $status == static::STATUS_RUNNING || $status == static::STATUS_STARTING;
    }

    /**
     * Tests if we are stopped
     * @return bool
     */
    public function isStopped()
    {
        return $this->getStatus() == static::STATUS_STOPPED;
    }

    /**
     * Tests if our configuration file exists
     * @return bool
     */
    public function isInstalled()
    {
        return file_exists($this->getConfName());
    }

    /**
     * Tests if supervisor stopped us because of failure
     *
     * @return bool
     */
    public function isFailed()
    {
        return $this->getStatus() == static::STATUS_FATAL;
    }

    /**
     * Tells supervisor to reload our configs
     */
    public function reload()
    {
        $shell = new Process('supervisorctl update');
        $shell->run();
    }

    /**
     * Tells supervisor we are ready to rock
     *
     * @return bool
     */
    public function start()
    {
        $shell = new Process('supervisorctl start ' . $this->getName());
        $shell->run();
        return $this->isRunningOrStarting();
    }

    /**
     * Tells supervisor to stop us as soon as possible
     *
     * @return bool
     */
    public function stop()
    {
        $shell = new Process('supervisorctl stop ' . $this->getName());
        $shell->run();
        return $this->isStopped();
    }

    /**
     * Collects errors when in managment stage
     * @param string $error
     */
    protected function error($error)
    {
        $this->errors[] = $error;
    }

    /**
     * Clears all errors
     */
    public function clearErrors()
    {
        $this->errors = [];
    }

    /**
     * Retrieves all errors and whipes the slate
     * @return array
     */
    public function getErrors()
    {
        $errors = $this->errors;
        $this->errors = [];
        return $errors;
    }

    public function isAutostart() {
        return $this->autostart;
    }

    public function setAutostart($autostart) {
        $this->autostart = $autostart;
    }

    public function cleanHostName($str, $replace = array(), $delimiter = '-')
    {
        if (!empty($replace)) {
            $str = str_replace((array)$replace, '', $str);
        }
        $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
        $clean = strtolower(trim($clean, '-'));
        $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
        return trim($clean);
    }

}
