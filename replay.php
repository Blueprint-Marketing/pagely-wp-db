<?php

if (isset($argv[1]))
{
	$conf = require $argv[1];
}
else
{
	$conf = require __DIR__.'/../../db-replay-config.php';
}
$statsFile = "/tmp/stats/$conf[name]-replay.log";
$checkPointFile = "/tmp/stats/$conf[name]-checkpoint.txt";

echo "Replaying $statsFile to $conf[host]\n";

mysqli_report(MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_STRICT ^ MYSQLI_REPORT_INDEX); 
$db = new mysqli($conf['host'], $conf['user'], $conf['password'], $conf['name']);
$db->set_charset('utf8mb4');
$db->query('set names utf8mb4 collate utf8mb4_unicode_ci');

$queryFile = realpath(__DIR__.'/../../../../tmp/db-queries.log');

$n = trim(`wc -l $queryFile`);
$fp = popen("tail -f -n $n", 'r');
$i = 0;

$checkPoint = '';
if (file_exists($checkPointFile))
{
	$checkPoint = file_get_contents($checkPointFile);
	echo "Starting with checkpoint: $checkPoint\n";
}
$last = time();
while($line = fgets($fp))
{
	$i++;

	$pos = strpos($line, ':');
	if ($pos === false)
	{
		echo Date('Y-m-d H:i:s T')." Bad line: '".rtrim($line)."'\n";
		continue;
	}
	$id = substr($line, 0, $pos);

	if (!empty($checkPoint))
	{
		$skip = true;
 		if ($id == $checkPoint)
		{
			echo Date('Y-m-d H:i:s T')." Skipped $i lines\n";
			$checkPoint = '';
		}
	}
	else
	{
		$skip = false;
	}

	if (!$skip)
	{
		$query = trim(substr($line, $pos+2));

		$t1 = microtime(true);
		$r = $db->query($query);
		$time = round(microtime(true)-$t1, 5);

		$rows = 'na';
		$type = 'na';
		if (is_bool($r))
		{
			$rows = $db->affected_rows;
			$type = 'u';
		}
		else
		{
			$rows = $r->num_rows;
			$type = 's';
		}

		file_put_contents($statsFile, "$id:\t$time\t$type$rows\n", FILE_APPEND);

		if ($i % 100 == 0 || (time() - $last) > 10)
		{
			$last = time();
			echo Date('Y-m-d H:i:s T')." $i $id\n";
			file_put_contents($checkPointFile, $id);	
		}
	}
}
