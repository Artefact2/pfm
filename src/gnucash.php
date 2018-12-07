<?php
/* Author: Romain Dal Maso <romain.dalmaso@artefact2.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function load_gnucash(string $path): \DOMDocument {
	if(!file_exists($path) || !is_readable($path)) {
		fatal("Could not read gnucash file at %s\n", $path);
	}

	$xml = shell_exec('zcat '.escapeshellarg($path));
	$d = new \DOMDocument();
	if($d->loadXML($xml) === false) {
		fatal("Could not parse XML in gnucash file %s\n", $path);
	}

	return $d;
}

function save_gnucash(string $path, \DOMDocument $d): void {
	$tmp = $path.'.tmp';
	if($d->save($tmp) === false) fatal("Could not save gnucash XML to %s\n", $tmp);
	passthru('gzip -9 '.escapeshellarg($tmp));
	$tmp .= '.gz';
	passthru('sync -d '.escapeshellarg($tmp));
	rename($tmp, $path);
}

function insert_gnucash_quotes(array &$pf, \DOMDocument $d): void {
	$commodities = [];

	foreach($d->getElementsByTagNameNS('http://www.gnucash.org/XML/gnc', 'commodity') as $c) {
		assert($c->getAttribute('version') === '2.0.0');

		$isin = null;
		$space = null;
		$id = null;
		$ticker = null;

		foreach($c->childNodes as $ch) {
			if($ch->namespaceURI !== 'http://www.gnucash.org/XML/cmdty') continue;
			if($ch->localName === 'space') $space = $ch->textContent;
			else if($ch->localName === 'id') $id = $ch->textContent;
			else if($ch->localName === 'xcode') $isin = $ch->textContent;
		}

		if($isin === null) continue;
		foreach($pf['lines'] as $t => $l) {
			if($l['isin'] === $isin) {
				$ticker = $t;
				break;
			}
		}

		if($ticker === null) continue;
		assert($id !== null && $space !== null);
		$commodities[$ticker] = [ $space, $id ];
	}

	$p = $d->getElementsByTagNameNS('http://www.gnucash.org/XML/gnc', 'pricedb');
	assert($p->length === 1);
	$p = $p->item(0);
	assert($p->getAttribute('version') === '1');

	$frag = $d->createDocumentFragment();
	$start = PHP_INT_MAX;
	foreach($pf['tx'] as $tx) {
		if($tx['ts'] < $start) $start = $tx['ts'];
	}
	foreach(iterate_time($pf, $start, time(), '+1 day') as $date => $data) {
		foreach($data['agg'] as $ticker => $agg) {
			if(!isset($commodities[$ticker])) continue;
			if($agg['qty'] < 0.0001) { /* XXX */
				continue;
			}

			list($space, $id) = $commodities[$ticker];
			$quote = get_quote($pf, $ticker, $date);
			if($quote === null) continue;

			$frag->appendXML(sprintf(
				'<price xmlns:price="http://www.gnucash.org/XML/price" xmlns:cmdty="http://www.gnucash.org/XML/cmdty" xmlns:ts="http://www.gnucash.org/XML/ts"><price:id type="guid">%s</price:id><price:commodity><cmdty:space>%s</cmdty:space><cmdty:id>%s</cmdty:id></price:commodity><price:currency><cmdty:space>CURRENCY</cmdty:space><cmdty:id>%s</cmdty:id></price:currency><price:time><ts:date>%s</ts:date></price:time><price:source>user:price-editor</price:source><price:type>unknown</price:type><price:value>%d/%d</price:value></price>',
				'pfm-'.$ticker.'-'.$date,
				$space,
				$id,
				$pf['lines'][$ticker]['currency'],
				gmdate('Y-m-d', $date).' 00:00:00 +0000',
				(int)($quote * 1000), 1000
			));
		}
	}

	$xp = new \DOMXPath($d);
	/* Only remove price entries for things we will replace */
	$rm = $xp->query('//gnc:pricedb/price/price:id[starts-with(text(),"pfm-")]/..');
	for($i = 0; $i < $rm->length; ++$i) {
		if($rm->item($i)->parentNode === null) continue;
		$rm->item($i)->parentNode->removeChild($rm->item($i));
		--$i;
	}
	$p->appendChild($frag);
}
