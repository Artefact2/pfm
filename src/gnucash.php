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

	/* XXX: smarter pruning */
	while($p->firstChild !== null) $p->removeChild($p->firstChild);

	$start = PHP_INT_MAX;
	foreach($pf['tx'] as $tx) {
		if($tx['ts'] < $start) $start = $tx['ts'];
	}
	foreach(iterate_time($pf, $start, time() - 86400, '+1 day') as $date => $data) {
		foreach($data['agg'] as $ticker => $agg) {
			if(!isset($commodities[$ticker])) continue;
			if($agg['qty'] < 0.0001) { /* XXX */
				continue;
			}

			list($space, $id) = $commodities[$ticker];
			$quote = get_quote($pf, $ticker, $date);
			if($quote === null) continue;

			$p->appendChild($price = $d->createElement('price'));

			$price->appendChild($nid = $d->createElementNS('http://www.gnucash.org/XML/price', 'id'));
			$nid->setAttribute('type', 'guid');
			$nid->appendChild($d->createTextNode(sha1('pfm-'.$ticker.'-'.$date)));

			$price->appendChild($commodity = $d->createElementNS('http://www.gnucash.org/XML/price', 'commodity'));
			$commodity->appendChild($nspace = $d->createElementNS('http://www.gnucash.org/XML/cmdty', 'space'));
			$nspace->appendChild($d->createTextNode($space));
			$commodity->appendChild($nid = $d->createElementNS('http://www.gnucash.org/XML/cmdty', 'id'));
			$nid->appendChild($d->createTextNode($id));

			$price->appendChild($currency = $d->createElementNS('http://www.gnucash.org/XML/price', 'currency'));
			$currency->appendChild($nspace = $d->createElementNS('http://www.gnucash.org/XML/cmdty', 'space'));
			$nspace->appendChild($d->createTextNode('CURRENCY'));
			$currency->appendChild($nid = $d->createElementNS('http://www.gnucash.org/XML/cmdty', 'id'));
			$nid->appendChild($d->createTextNode($pf['lines'][$ticker]['currency']));

			$price->appendChild($time = $d->createElementNS('http://www.gnucash.org/XML/price', 'time'));
			$time->appendChild($ndate = $d->createElementNS('http://www.gnucash.org/XML/ts', 'date'));
			$ndate->appendChild($d->createTextNode(date('Y-m-d', $date).' 00:00:00 +0000'));

			$price->appendChild($source = $d->createElementNS('http://www.gnucash.org/XML/price', 'source'));
			$source->appendChild($d->createTextNode('user:price-editor'));

			$price->appendChild($type = $d->createElementNS('http://www.gnucash.org/XML/price', 'type'));
			$type->appendChild($d->createTextNode('unknown'));

			$price->appendChild($value = $d->createElementNS('http://www.gnucash.org/XML/price', 'value'));
			$value->appendChild($d->createTextNode(sprintf('%d/%d', (int)($quote * 1000), 1000)));;

		}
	}
}
