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
		'TxID' => [ '%6s' ],
		'Tkr' => [ '%5s' ],
		'Date' => [ '%11s' ],
		'Act' => [ '%5s' ],
		'Price' => [ '%11s', '%11.4f' ],
		'Quantity' => [ '%11s', '%11.4f' ],
		'Fee' => [ '%8s', '%8.2f' ],
		'Total' => [ '%9s', '%9.0f' ],
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
			'Act' => $tx['buy'] > 0 ? 'Buy' : 'Sell',
			'Price' => (float)$tx['price'],
			'Quantity' => abs($tx['buy']),
			'Fee' => (float)$tx['fee'],
			'Total' => -$tx['price']*$tx['buy'] - $tx['fee'],
		]);
	}
}

function aggregate_tx(array $pf, array $filters = []) {
	$agg = [];

	$before = isset($filters['before']) ? maybe_strtotime($filters['before']) : null;

	foreach($pf['tx'] as $tx) {
		if($before !== null && $tx['ts'] > $before) continue;
		
		$ticker = $tx['ticker'];
		
		if(!isset($agg[$ticker])) {
			$agg[$ticker] = [
				'in' => 0.0,
				'qty' => 0.0,
			];
		}

		$agg[$ticker]['in'] += $tx['fee'] + $tx['buy']*$tx['price'];
		$agg[$ticker]['qty'] += $tx['buy'];
	}
	
	return $agg;
}
