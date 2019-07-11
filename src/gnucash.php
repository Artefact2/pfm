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
	$today = date('Y-m-d', time());
	$agg = aggregate_tx($pf);

	/* XXX: not optimal at all */
	foreach($commodities as $ticker => list($space, $id)) {
		$hist = $pf['hist'][$ticker] ?? [];

		if($agg[$ticker]['qty'] > 1e-5) {
			$hist[$today] = get_quote($pf, $ticker);
		}

		foreach($hist as $ymd => $q) {
			if($q === null) continue;
			$frag->appendXML(sprintf(
				'<price xmlns:price="http://www.gnucash.org/XML/price" xmlns:cmdty="http://www.gnucash.org/XML/cmdty" xmlns:ts="http://www.gnucash.org/XML/ts"><price:id type="guid">%s</price:id><price:commodity><cmdty:space>%s</cmdty:space><cmdty:id>%s</cmdty:id></price:commodity><price:currency><cmdty:space>CURRENCY</cmdty:space><cmdty:id>%s</cmdty:id></price:currency><price:time><ts:date>%s</ts:date></price:time><price:source>user:price-editor</price:source><price:type>nav</price:type><price:value>%d/%d</price:value></price>',
				'pfm-'.$ticker.'-'.$ymd,
				$space,
				$id,
				$pf['lines'][$ticker]['currency'],
				$ymd.' 00:00:00 +0000',
				(int)($q * 1000), 1000
			));
		}
	}

	$xp = new \DOMXPath($d);
	/* Only remove price entries for things we will replace */
	/* https://github.com/Gnucash/gnucash/blob/master/libgnucash/doc/xml/gnucash-v2.rnc */
	$rm = $xp->query('//gnc:pricedb/price/price:type[text()="nav"]/..'); /* XXX: nav type doesn't perfectly identify prices added by pfm */
	for($i = 0; $i < $rm->length; ++$i) {
		if($rm->item($i)->parentNode === null) continue;
		$rm->item($i)->parentNode->removeChild($rm->item($i));
		--$i;
	}
	$p->appendChild($frag);
}
