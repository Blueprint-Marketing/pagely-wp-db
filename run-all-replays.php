<?php
declare(ticks = 1);
function sig_handler($signo)
{
	echo "Killing children\n";
	global $procs;

	foreach($procs as $proc)
	{
		proc_terminate($proc);
	}
}
pcntl_signal(SIGTERM, "sig_handler");

if (!file_exists('/tmp/replay-logs'))
	mkdir('/tmp/replay-logs');
$spec = [
	2 => array("file", "/tmp/replay-errors.log", "a")
];

while(true)
{
	$confs = glob("/data/s*/dom*/httpdocs/wp-content/db-replay-config.php");
	foreach($confs as $conf)
	{
		if (isset($procs[$conf]))
			continue;
		$pipes = [];
		$name = 'foobar.log';
		if (preg_match('/(dom[0-9]+)/', $conf, $match))
			$name = "$match[1].log";
		$spec[1] = array("file", "/tmp/replay-logs/$name", "a");
		echo "Starting replay on $conf\n";
		$procs[$conf] = proc_open("php replay.php $conf", $spec, $pipes, __DIR__);
		sleep(1);
	}
	sleep(10);
}
