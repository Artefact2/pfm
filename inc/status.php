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
		'Perf' => [ '%6s', '%6.1f' ],
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

function perf(array &$pf, $date = 'now', $columns = 'default') {
	$ts = maybe_strtotime($date);
    
	$fmt = [
		'Ticker' => [ '%8s' ],
	];

	switch($columns) {
		
	case 'default':
		$startday = strtotime('-1 day', strtotime(date('Y-m-d', $ts)));
		$periods[] = [
			'Day', $startday, $ts, '%6.2f', '%6s'
		];
		
		$periods[] = [
			'WtD', strtotime('last sunday', $ts), $ts, '%5.1f', '%5s'
		];

		$startmonth = strtotime(date('Y-m-01', $ts));
		$periods[] = [
			'MtD', $startmonth, $ts, '%5.1f', '%5s'
		];

		$startyear = strtotime(date('Y-01-01', $ts));
		$periods[] = [
			'YtD', $startyear, $ts, '%5.1f', '%5s'
		];

		for($i = 0; $i < 3; ++$i) {
			$prevmonth = strtotime('-1 month', $startmonth);
			$periods[] = [
				date('M', $prevmonth), $prevmonth, $startmonth, '%5.1f', '%5s'
			];
			$startmonth = $prevmonth;
		}

		for($i = 0; $i < 2; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $prevyear), $prevyear, $startyear, '%5.1f', '%5s'
			];
			$startyear = $prevyear;
		}

		$periods[] = [
			'All', 0, $ts, '%6.1f', '%6s'
		];
		break;

	case 'days':
		$start = strtotime(date('Y-m-d', strtotime('-1 day', $ts)));
		$periods[] = [
			date('W-N', $ts), $start, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prev = strtotime('-1 day', $start);
			if(in_array(date('N', $start), [ '6', '7' ], true)) {
				--$i;
			} else {
				$periods[] = [
					date('W-N', $start), $prev, $start, '%5.1f', '%5s'
				];
			}
			$start = $prev;
		}
		break;

	case 'weeks':
		$start = strtotime(date('Y-m-d', strtotime('last Sunday', $ts)));
		$periods[] = [
			'WtD', $start, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prev = strtotime('-1 week', $start);
			$periods[] = [
				date('\WW', $start), $prev, $start, '%5.1f', '%5s'
			];
			$start = $prev;
		}
		break;

	case 'months':
		$startmonth = strtotime(date('Y-m-01', $ts));
		$periods[] = [
			'MtD', $startmonth, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prevmonth = strtotime('-1 month', $startmonth);
			$periods[] = [
				date('M', $prevmonth), $prevmonth, $startmonth, '%5.1f', '%5s'
			];
			$startmonth = $prevmonth;
		}
		break;

	case 'years':
		$startyear = strtotime(date('Y-01-01', $ts));
		$periods[] = [
			'YtD', $startyear, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $prevyear), $prevyear, $startyear, '%5.1f', '%5s'
			];
			$startyear = $prevyear;
		}
		break;

	default:
		fatal("perf(): unknown column type %s\n", $columns);
		break;
		
	}

	foreach($periods as $p) {			
		$fmt[$p[0]] = [
			$p[4], $p[4]
		];
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

			$row[$k] = colorize_percentage(100.0 * ($me - $ms) / $ms, $p[3]);
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
		
		$row[$k] = colorize_percentage(100.0 * ($me - $ms) / $ms, $p[3]);
	}

	print_row($fmt, $row);
}