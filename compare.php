<?php
$source = $argv[1];
$compare = $argv[2];

$data = [];
$summary = ['total_time' => 0, 'total_queries' => 0, 'update_time' => 0, 'update_queries' => 0, 'select_time' => 0, 'select_queries' => 0];
$compare_summary = $source_summary = $summary;

$fp = fopen($source, 'r');
while($line = fgets($fp))
{
	$pos = strpos($line, ':');
        if ($pos === false)
        {
                echo Date('Y-m-d H:i:s T')." Bad line: '".rtrim($line)."'\n";
                continue;
        }
	
	$id = substr($line, 0, $pos);
	list($time, $rows) = explode("\t", trim(substr($line, $pos+2)));

	$data[$id] = ['time' => $time, 'rows' => $rows];
	$source_summary['total_time'] += $time;
	$source_summary['total_queries']++;
	if ($rows[0] == 'u')
	{
		$source_summary['update_time'] += $time;
		$source_summary['update_queries']++;
	}
	else
	{
		$source_summary['select_time'] += $time;
		$source_summary['select_queries']++;
	}
		
}

$fp = fopen($compare, 'r');
while($line = fgets($fp))
{
	$pos = strpos($line, ':');
        if ($pos === false)
        {
                echo Date('Y-m-d H:i:s T')." Bad line: '".rtrim($line)."'\n";
                continue;
        }
	
	$id = substr($line, 0, $pos);
	list($time, $rows) = explode("\t", trim(substr($line, $pos+2)));

	if (!isset($data[$id]))
	{
                echo Date('Y-m-d H:i:s T')." Missing $id in source\n";
	}
	else
	{
		if ($data[$id]['rows'] != $rows)
		{
			echo Date('Y-m-d H:i:s T')." Different query result: {$data[$id]['rows']} != $rows\n";
		}
	}

	$compare_summary['total_time'] += $time;
	$compare_summary['total_queries']++;
	if ($rows[0] == 'u')
	{
		$compare_summary['update_time'] += $time;
		$compare_summary['update_queries']++;
	}
	else
	{
		$compare_summary['select_time'] += $time;
		$compare_summary['select_queries']++;
	}
}

function summary($data)
{
	foreach(['total', 'update', 'select'] as $type)
	{
		$tkey = "{$type}_time";
		$qkey = "{$type}_queries";
		$per = round($data[$tkey]/$data[$qkey], 5);
		echo "$type {$data[$tkey]} {$data[$qkey]} $per\n";
	}
}

echo "Source\n";
summary($source_summary);
echo "Compare\n";
summary($compare_summary);
