<?php

/**
 * The Supervisor package manages child processes
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   1997-Present Brian Moon
 * @package     Supervisor
 *
 */

namespace Moonspot\Supervisor;

// allows us to register to catch process signals
declare (ticks = 1);

/**
 * The Supervisor class will manage child process by taking a callback to be
 * called after the child is forked. It will restart new children to replace
 * ones that exit.
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   2015-Present Brian Moon
 * @package     Moonspot\Supervisor
 *
 */
class Supervisor
{
    /**
     * When set to true, all children will be stopped and the wait function
     * will return
     *
     * @var boolean
     */
    protected $stopChildren = false;

    /**
     * Whent set to true, all children will be stopped. The parent process
     * will restart new ones.
     *
     * @var boolean
     */
    protected $restartChildren = false;

    /**
     * This is set to true in the parent process
     *
     * @var boolean
     */
    protected $isParent = true;

    /**
     * A list of children that are being managed
     *
     * @var array
     */
    protected $children = array();

    /**
     * The list of callbacks that are being called by the children
     *
     * @var array
     */
    protected $callbacks = array();

    /**
     * A callback that will be called during the loop that monitors
     * the children processes. The calling code can use this callback
     * to call stop() or restart() when it's needed
     *
     * @var callback
     */
    protected $monitorCallback;

    /**
     * A callback that will be called for any data that Supervisor wants
     * to have logged. Rather than having it's own log, the calling code
     * can do what it wants with the log data.
     *
     * @var callback
     */
    protected $logCallback;

    /**
     * This is a callback that is called in the child processes when process
     * signals are received. Children processes need to handle signals and
     * take the appropriate action.
     *
     * @var callback
     */
    protected $signalHandler;

    /**
     * When set to greater than zero, Supervisor will delay this number of
     * milliseconds before starting a new worker. This is to prevent a sudden
     * spike of resource usage by children that may all be trying to use the
     * same resources
     *
     * @var integer
     */
    protected $startupSplay = 0;

    /**
     * This stores the time in milliseconds that Supervisor will next check
     * if any workers have been running too long. When adding a child, you
     * have the option to set a max run time. See addChild for more.
     *
     * @var float
     */
    protected $nextCheckTime;

    /**
     * Creates a new Supervisor
     *
     * @param callable $monitor       Callback that will be called during the
     *                                process loop to allow the calling code
     *                                to monitor things and stop Supervisor
     *                                if needed. This function is called very,
     *                                very often. Take caution with what you do
     *                                in it.
     * @param callable $log           Callback that will take a string of data
     *                                and do something with it.
     * @param callable $signalHandler Callback that will handle signals for the
     *                                child processes
     * @param integer  $startupSplay  Time in milliseconds to delay between
     *                                starting workers at startup and when
     *                                restart is called.
     */
    public function __construct(callable $monitor, callable $log, callable $signalHandler, $startupSplay = 0)
    {
        $this->monitorCallback = $monitor;
        $this->logCallback = $log;
        $this->signalHandler = $signalHandler;
        $this->startupSplay = $startupSplay;
    }

    /**
     * Adds a callback to the callback list for which a child will be created.
     * There is no checking for duplicate children. In fact, it would be common
     * to have the same callback used for many children in some cases.
     *
     * @param callable $callback   Callback to be called by the child after it
     *                             is forked.
     * @param array    $args       Arguments to be passed into the callback
     * @param integer  $maxRunTime Maximum time in milliseconds the child
     *                             process should be allowed to run before
     *                             being killed. This is not a restart. The
     *                             child process with be sent a SIGKILL and all
     *                             work it is doing will be halted. This should
     *                             be used only to kill run away processes. If
     *                             the child needs to terminate after some time,
     *                             the callback passed in should handle that
     *                             and the child should exit when it is ready.
     */
    public function addChild(callable $callback, array $args = array(), $maxRunTime = 0)
    {
        $callbackId = uniqid();

        $this->callbacks[$callbackId] = array(
            "callback" => $callback,
            "args" => $args,
            "maxRunTime" => $maxRunTime
        );

    }

    /**
     * This is the main process loop that forks and monitors the child
     * processes. It should be called after all the desired children have
     * been added. This will block the calling code. The only hooks into the
     * calling code are the callbacks passed to the constructor.
     */
    public function wait()
    {
        $this->registerTicks();

        foreach($this->callbacks as $callbackId => $callback){
            $this->startChild($callbackId);
            usleep($this->startupSplay);
        }

        $restartPids = array();

        while(!$this->stopChildren || count($this->children)){

            // Check for exited children
            $exited = pcntl_wait($status, WNOHANG);

            if(isset($this->children[$exited])){

                $code = pcntl_wexitstatus($status);
                $this->log(
                    "Child $exited exited with error code of $code"
                );

                if(!$this->stopChildren){
                    $callbackId = $this->children[$exited]["callbackId"];
                    $this->startChild($callbackId);
                }

                unset($this->children[$exited]);

            }

             // php will eat up your cpu if you don't have this
            usleep(50000);

            if($this->restartChildren){
                $restartPids = array_keys($this->children);
                $this->restartChildren = false;
            }

            if(count($restartPids) > 0){
                if($this->startupSplay > 0){
                    $now = microtime(true)*1000;
                    if(empty($lastKillTime) || $lastKillTime < $now - $this->startupSplay){
                        $killPid = array_shift($restartPids);
                        // The child may have died already
                        if(isset($this->children[$pid])){
                            $this->termChild($killPid, SIGTERM);
                        }
                        $lastKillTime = $now;
                    }
                } else {
                    $this->termChildren();
                    $restartPids = array();
                }
            }

            if(!is_null($this->nextCheckTime)){
                $now = microtime(true) * 1000;
                if($this->nextCheckTime <= $now){
                    $this->log("Checking children max run times");
                    $this->nextCheckTime = null;
                    foreach($this->children as $pid => $child){
                        if($child["killTime"] > 0){
                            if($now > $child["killTime"]){
                                $this->log("Killing child $pid. It has been running too long.");
                                $this->termChild($pid, SIGKILL);
                            } elseif(!$this->stopChildren && (is_null($this->nextCheckTime) || $this->nextCheckTime > $child["killTime"])){
                                $this->nextCheckTime = $child["killTime"];
                                $this->log("Setting next check time to ".($child["killTime"]));
                            }
                        }
                    }
                }
            }

            call_user_func($this->monitorCallback);

        }
    }

    /**
     * The calling code can use the monitor callback to call this method when
     * it wants Supervisor to kill the children and return
     */
    public function stop()
    {
        if(!$this->stopChildren){
            $this->stopChildren = true;
            $this->log("Stopping children");
            $this->termChildren();
        }
    }

    /**
     * The calling code can use the monitor callback to signal Supervisor to
     * restart all children. The running children will be killed and new
     * children will be created in their place.
     */
    public function restart()
    {
        if(!$this->stopChildren){
            $this->log("Restarting children");
            $this->restartChildren = true;
        }
    }

    /**
     * This method is used to send a signal to all children
     *
     * @param  int $signal Signal to send to children
     */
    protected function termChildren($signal = SIGTERM)
    {
        foreach($this->children as $pid => $child){
            $this->log("Stopping child $pid");
            $this->termChild($pid, $signal);
        }
    }

    /**
     * This method is used to send a signal to a single child. In addition, it
     * will set the childs kill time so that the child will be killed if it
     * does not shut down on its own.
     *
     * @param  int $pid    PID of the child to signal
     * @param  int $signal Signal to send to children
     */
    protected function termChild($pid, $signal = SIGTERM)
    {
        if(isset($this->children[$pid])){
            posix_kill($pid, $signal);
            // Set the kill time to 60 seconds from now
            // TODO: make this 60 seconds a setting
            $this->children[$pid]["killTime"] = (microtime(true)*1000) + 60000;
            // Reset the next child check time to cause an immediate check
            $this->nextCheckTime = 0;
        }
    }

    /**
     * Forks a child process. For the child process, the callback is then
     * called. For the parent process, it adds the child to the child list.
     *
     * @param  string $callbackId The key of the callback to create a child for
     */
    protected function startChild($callbackId)
    {
        $pid = pcntl_fork();
        switch ($pid) {
            case 0:
                $this->isParent = false;
                call_user_func_array(
                    $this->callbacks[$callbackId]["callback"],
                    $this->callbacks[$callbackId]["args"]
                );
                exit(0);
                break;
            case -1:
                $this->log("Failed to fork");
                $this->stopChildren = true;
                break;
            default:
                $now = microtime(true) * 1000;
                $this->children[$pid] = array(
                    "callbackId" => $callbackId,
                    "pid" => $pid,
                    "startTime" => $now,
                    "killTime" => 0,
                );
                if($this->callbacks[$callbackId]["maxRunTime"] > 0){
                    $this->children[$pid]["killTime"] = $now + $this->callbacks[$callbackId]["maxRunTime"];
                    if(is_null($this->nextCheckTime) || $this->nextCheckTime > $this->children[$pid]["killTime"]){
                        $this->nextCheckTime = $this->children[$pid]["killTime"];
                        $this->log("Setting next check time to ".($this->children[$pid]["killTime"]));
                    }
                }
                $this->log("Child forked with pid $pid");
                break;
        }
    }

    /**
     * Registers the process signal listeners.
     */
    protected function registerTicks()
    {
        $this->log("Registering signals for parent");
        pcntl_signal(SIGTERM, array($this, "signal"));
        pcntl_signal(SIGINT, array($this, "signal"));
        pcntl_signal(SIGHUP, array($this, "signal"));
    }

    /**
     * Handles signals.
     */
    public function signal($signo)
    {
        static $term_count = 0;

        if ($this->isParent) {
            switch ($signo) {
                case SIGINT:
                case SIGTERM:
                    $this->log("Shutting down...");
                    $term_count++;
                    if ($term_count < 5) {
                        $this->stop();
                    } else {
                        $this->termChildren(SIGKILL);
                    }
                    break;
                case SIGHUP:
                    $this->restart();
                    break;
            }
        } else {
            call_user_func($this->signalHandler, $signo);
        }
    }

    /**
     * Wrapper for the log callback to make it easier to call throughout
     * the class.
     *
     * @param  string $log A log message to be sent to the callback
     */
    protected function log($log) {
        call_user_func($this->logCallback, $log);
    }

}
