<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function add_tx(array &$pf, $ticker, $buy, $price, $fee, $date) {
	$ts = maybe_strtotime($date);

	$pf['tx'][] = [
		'ticker' => $ticker,
		'buy' => (float)$buy,
		'price' => (float)$price,
		'fee' => (float)$fee,
		'ts' => $ts,
	];
}

function rm_tx(array &$pf, array $txids) {
	foreach($txids as $k) {
		if(!isset($pf['tx'][$k])) {
			fatal("Transaction id %d not found\n", $k);
		}

		unset($pf['tx'][$k]);
	}
}

function ls_tx(array &$pf, array $filters = []) {
	static $fmt = [
		'TxID' => [ '%5s' ],
		'Tkr' => [ '%5s' ],
		'Date' => [ '%11s' ],
		'Price' => [ '%11s', '%11.4f' ],
		'Quantity' => [ '%12s', '%12.4f' ],
		'Fee' => [ '%10s', '%10.2f' ],
		'Total' => [ '%14s', '%14.2f' ],
	];

	print_header($fmt);
	print_sep($fmt);

	$ticker = $filters['ticker'] ?? null;
	$before = isset($filters['before']) ? maybe_strtotime($filters['before']) : null;
	$after = isset($filters['after']) ? maybe_strtotime($filters['after']) : null;

	foreach($pf['tx'] as $k => $tx) {
		if($ticker !== null && $ticker !== $tx['ticker']) continue;
		if($before !== null && $tx['ts'] > $before) continue;
		if($after !== null && $tx['ts'] < $after) continue;

		print_row($fmt, [
			'TxID' => (string)$k,
			'Tkr' => $tx['ticker'],
			'Date' => date('Y-m-d', $tx['ts']),
			'Price' => (float)$tx['price'],
			'Quantity' => $tx['buy'],
			'Fee' => (float)$tx['fee'],
			'Total' => -$tx['price']*$tx['buy'] - $tx['fee'],
		]);
	}
}

function aggregate_tx(array $pf, array $filters = [], array &$totals = null) {
	$before = $filters['before'] ?? 'now';

	foreach(iterate_time($pf, $before, $before) as $res) {
		$totals = $res['totals'];
		return $res['agg'];
	}
}

/* XXX: use iterate_tx */
function iterate_time(array $pf, $start, $end, $interval = '+1 day') {
	$start = maybe_strtotime($start);
	$end = maybe_strtotime($end);

	$txs = $pf['tx'];
	reset($txs);
	$tx = current($txs);

	static $blank = [
		'in' => 0.0,
		'out' => 0.0,
		'realized' => 0.0,
		'qty' => 0.0,
	];

	$agg = [];
	$bagg = [];
	$totals = $blank;
	$btotals = $blank;
	$first = true;

	while($start <= $end) {
		$delta = [];
		$bdelta = [];
		$dtotals = $blank;
		$bdtotals = $blank;

		while($tx !== false && $tx['ts'] <= $start) {
			$tkr = $tx['ticker'];

			if(!isset($agg[$tkr])) $agg[$tkr] = $blank;
			if(!isset($delta[$tkr])) $delta[$tkr] = $blank;

			foreach($pf['benchmark'] ?? [] as $bt => $bw) {
				if(!isset($bagg[$bt])) $bagg[$bt] = $blank;
				if(!isset($bdelta[$bt])) $bdelta[$bt] = $blank;
			}

			if($tx['buy'] > 0) {
				$in = $tx['buy'] * $tx['price'];

				$agg[$tkr]['in'] += $in;
				$delta[$tkr]['in'] += $in;
				$totals['in'] += $in;
				$dtotals['in'] += $in;

				foreach($pf['benchmark'] ?? [] as $bt => $bw) {
					$bagg[$bt]['in'] += $bw * $in;
					$bdelta[$bt]['in'] += $bw * $in;
					$btotals['in'] += $bw * $in;
					$bdtotals['in'] += $bw * $in;
				}
			} else if($tx['buy'] < 0) {
				$out = -$tx['buy'] * (($agg[$tkr]['in'] - $agg[$tkr]['out']) / $agg[$tkr]['qty']);
				$realized = -$tx['buy'] * $tx['price'] - $out;

				$agg[$tkr]['out'] += $out;
				$delta[$tkr]['out'] += $out;
				$totals['out'] += $out;
				$dtotals['out'] += $out;

				$agg[$tkr]['realized'] += $realized;
				$delta[$tkr]['realized'] += $realized;
				$totals['realized'] += $realized;
				$dtotals['realized'] += $realized;

				foreach($pf['benchmark'] ?? [] as $bt => $bw) {
					$qout = $tx['buy'] * $tx['price'] * $bw / get_quote($pf, $bt, (string)$tx['ts']);
					$bout = -$qout * ($bagg[$bt]['in'] - $bagg[$bt]['out']) / $bagg[$bt]['qty'];
					$brealized = -$tx['buy'] * $tx['price'] * $bw - $bout;

					$bagg[$bt]['out'] += $bout;
					$bdelta[$bt]['out'] += $bout;
					$btotals['out'] += $bout;
					$bdtotals['out'] += $bout;

					$bagg[$bt]['realized'] += $brealized;
					$bdelta[$bt]['realized'] += $brealized;
					$btotals['realized'] += $brealized;
					$bdtotals['realized'] += $brealized;
				}
			}

			$agg[$tkr]['qty'] += $tx['buy'];
			$delta[$tkr]['qty'] += $tx['buy'];

			$agg[$tkr]['realized'] -= $tx['fee'];
			$delta[$tkr]['realized'] -= $tx['fee'];
			$totals['realized'] -= $tx['fee'];
			$dtotals['realized'] -= $tx['fee'];

			if(abs($agg[$tkr]['qty']) < 1e-6) $agg[$tkr]['qty'] = 0;

			foreach($pf['benchmark'] ?? [] as $bt => $bw) {
				$bqty = $tx['buy'] * $tx['price'] * $bw / get_quote($pf, $bt, (string)$tx['ts']); /* XXX: intraday precision (hard!) */

				$bagg[$bt]['qty'] += $bqty;
				$bdelta[$bt]['qty'] += $bqty;

				$bagg[$bt]['realized'] -= $tx['fee'] * $bw;
				$bdelta[$bt]['realized'] -= $tx['fee'] * $bw;
				$btotals['realized'] -= $tx['fee'] * $bw;
				$bdtotals['realized'] -= $tx['fee'] * $bw;

				if(abs($bagg[$bt]['qty']) < 1e-6) $bagg[$bt]['qty'] = 0;
			}

			$tx = next($txs);
		}

		if($first === true) {
			/* Reset benchmark value to pf value at starting date */
			$first = false;
			$in = 0.0;

			foreach($agg as $ftkr => $fd) {
				if($fd['qty'] > 1e-5) {
					$in += get_quote($pf, $ftkr, (string)$start) * $fd['qty'];
				}
			}

			$bagg = $bdelta = [];
			$btotals = $bdtotals = $blank;

			foreach($pf['benchmark'] ?? [] as $bt => $bw) {
				$bagg[$bt] = $bdelta[$bt] = $blank;

				$bagg[$bt]['in'] += $in * $bw;
				$bdelta[$bt]['in'] += $in * $bw;
				$btotals['in'] += $in * $bw;
				$bdtotals['in'] += $in * $bw;

				$bagg[$bt]['qty'] += $in * $bw / get_quote($pf, $bt, (string)$start);
				$bdelta[$bt]['qty'] += $in * $bw / get_quote($pf, $bt, (string)$start);
			}
		}

		yield $start => [
			'agg' => $agg,
			'delta' => $delta,
			'totals' => $totals,
			'dtotals' => $dtotals,

			'bagg' => $bagg,
			'bdelta' => $bdelta,
			'btotals' => $btotals,
			'bdtotals' => $bdtotals,
		];

		if($start === $end) {
			return;
		}

		$start = strtotime($interval, $start);
		if($start > $end) $start = $end;
	}
}
