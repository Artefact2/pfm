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
		if($l['qty']) {
			$l['price'] = get_quote($pf, $t, $date);
		} else $l['price'] = 0.0;
		
		$totalv += $l['qty']*$l['price'];
		$totalin += $l['in'];
	}
	
	foreach($pf['lines'] as $line) {
		if(!isset($agg[$line['ticker']])) continue;
		$a = $agg[$line['ticker']];
		
		print_row($fmt, [
			'Tkr' => $line['ticker'],
			'Price' => $a['qty'] ? (float)$a['price'] : '',
			'Quantity' => (float)$a['qty'],
			'Money in' => (float)$a['in'],
			'Value' => $a['qty'] ? (float)($a['qty']*$a['price']) : '',
			'Gain' => (float)($a['qty']*$a['price'] - $a['in']),
			'%Wgt' => $a['qty'] ? 100.0 * $a['qty']*$a['price'] / $totalv : '',
			'Perf' => $a['qty'] ? 100.0 * ($a['price'] - $a['in']/$a['qty']) / ($a['in']/$a['qty']) : '',
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

function perf(array &$pf, $date = 'now') {	
	static $fmt = null;
	static $periods = null;

	$ts = maybe_strtotime($date);
	
	if($fmt === null) {
		$fmt = [
			'Ticker' => [ '%8s' ],
		];

		$startday = strtotime('-1 day', strtotime(date('Y-m-d', $ts)));
		$periods[] = [
			'Day', $startday, $ts
		];

		$startmonth = strtotime(date('Y-m-01', $ts));
		$periods[] = [
			'MtD', $startmonth, $ts
		];

		$startyear = strtotime(date('Y-01-01', $ts));
		$periods[] = [
			'YtD', $startyear, $ts
		];

		for($i = 0; $i < 3; ++$i) {
			$prevmonth = strtotime('-1 month', $startmonth);
			$periods[] = [
				date('M', $prevmonth), $prevmonth, $startmonth
			];
			$startmonth = $prevmonth;
		}

		for($i = 0; $i < 2; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $prevyear), $prevyear, $startyear
			];
			$startyear = $prevyear;
		}

		$periods[] = [
			'All', 0, $ts
		];

		foreach($periods as $p) {
			$fmt[$p[0]] = [
				'%6s', '%s'
			];
		}
	}

	print_header($fmt);
	print_sep($fmt);

	$aggs = [];
	
	foreach($periods as $p) {
		for($i = 1; $i <= 2; ++$i) {
			if(!isset($aggs[$p[$i]])) {
				$aggs[$p[$i]] = aggregate_tx($pf, [ 'before' => $p[$i] ]);
			}
		}
	}

	foreach($aggs as $k => &$a) {
		foreach($a as $ticker => &$l) {
			$l['price'] = get_quote($pf, $ticker, $k);
		}
	}
	unset($a, $l);

	$totals = [];
	foreach($aggs as $t => $a) {
		$totals[$t] = [
			'in' => 0.0,
			'value' => 0.0,
		];
		
		foreach($a as $l) {
			$totals[$t]['in'] += $l['in'];
			$totals[$t]['value'] += $l['qty']*$l['price'];
		}
	}

	foreach($pf['lines'] as $l) {
		$t = $l['ticker'];
		$show = false;
		foreach($aggs as $a) {
			if(isset($a[$t]) && $a[$t]['qty'] > 0) {
				$show = true;
				break;
			}
		}
		if($show === false) continue;

		$row = [ 'Ticker' => $t ];

		foreach($periods as $p) {
			list($k, $start, $end) = $p;
			if(!isset($aggs[$end][$t])) continue;
			if(!isset($aggs[$start][$t])) {
				$aggs[$start][$t] = [
					'qty' => 0.0,
					'in' => 0.0,
					'price' => 0.0,
				];
			}
			if(!$aggs[$start][$t]['qty'] && !$aggs[$end][$t]['qty']) continue;

			$s = $aggs[$start][$t];
			$e = $aggs[$end][$t];

			$me = $e['qty'] * $e['price'];
			$ms = $s['qty'] * $s['price'];

			if($e['in'] > $s['in']) {
				$ms += $e['in'] - $s['in'];
			} else if($s['in'] > $e['in']) {
				$me += $s['in'] - $e['in'];
			}

			$row[$k] = colorize_percentage(100.0 * ($me - $ms) / $ms, '%6.2f');
		}

		print_row($fmt, $row);
	}

	print_sep($fmt);

	$row = [ 'Ticker' => 'TOTAL' ];
	
	foreach($periods as $p) {
		list($k, $start, $end) = $p;

		$me = $totals[$end]['value'];
		$ie = $totals[$end]['in'];
		$ms = $totals[$start]['value'];
		$is = $totals[$start]['in'];

		if($ie > $is) {
			$ms += $ie - $is;
		} else if($is > $ie) {
			$me += $is - $ie;
		}

		if(!$ms) continue;
		
		$row[$k] = colorize_percentage(100.0 * ($me - $ms) / $ms, '%6.2f');
	}

	print_row($fmt, $row);
}