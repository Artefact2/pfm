<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function status(array &$pf, $date = 'now') {
	static $fmt = [
		'Tkr' => [ '%5s' ],
		'Price' => [ '%9s', '%9.2f' ],
		'Quantity' => [ '%10s', '%10.4f' ],
		'Money in' => [ '%10s', '%10.2f' ],
		'Value' => [ '%10s', '%10.2f' ],
		'Gain' => [ '%10s', '%10.2f' ],
		'%Wgt' => [ '%6s', '%6.2f' ],
		'Perf' => [ '%6s', '%6.2f' ],
	];

	print_header($fmt);
	print_sep($fmt);

	$agg = aggregate_tx($pf, [
		'before' => $date,
	]);
	$totalv = 0;
	$totalin = 0;
	foreach($agg as $t => &$l) {
		if($l['qty'] !== 0) {
			$l['price'] = get_quote($pf, $t, $date);
		} else $l['price'] = 0.0;
		
		$totalv += $l['qty']*$l['price'];
		$totalin += $l['in'];
	}
	
	foreach($pf['lines'] as $line) {
		if(!isset($agg[$line['ticker']]) || $agg[$line['ticker']]['qty'] === 0.0) continue;
		$a = $agg[$line['ticker']];
		
		print_row($fmt, [
			'Tkr' => $line['ticker'],
			'Price' => (float)$a['price'],
			'Quantity' => (float)$a['qty'],
			'Money in' => (float)$a['in'],
			'Value' => (float)($a['qty']*$a['price']),
			'Gain' => (float)($a['qty']*$a['price'] - $a['in']),
			'%Wgt' => 100.0 * $a['qty']*$a['price'] / $totalv,
			'Perf' => 100.0 * ($a['price'] - $a['in']/$a['qty']) / ($a['in']/$a['qty']),
		]);
	}

    print_sep($fmt);

	print_row($fmt, [
		'Tkr' => 'TOT',
		'Money in' => $totalin,
		'Value' => $totalv,
		'Gain' => $totalv - $totalin,
		'Perf' => $totalin !== 0 ? 100.0 * ($totalv - $totalin) / $totalin : 0,
	]);
}
