<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

require __DIR__.'/inc.php';

ob_start();

$pfp = get_pf_path();
$pf = load_pf($pfp);

if($argc === 1) {
	$argv[] = 'version';
	++$argc;
}

$me = array_shift($argv);
$cmd = array_shift($argv);

$args = [];
foreach($argv as $arg) {
	if(strpos($arg, ':') === false) {
		$args[] = $arg;
	} else {
		list($k, $v) = explode(':', $arg, 2);
		$args[$k] = $v;
	}
}

switch($cmd) {

case 'status':
case 's':
	status($pf, $args['at'] ?? 'now');
	break;

case 'perf':
case 'p':
	perf($pf, $args['at'] ?? 'now', $args['columns'] ?? 'default');
	break;

case 'add-line':
	foreach([ 'name', 'ticker', 'currency', 'isin' ] as $k) {
		isset($args[$k]) || fatal("Missing argument for add-line: %s\n", $k);
	}
	add_line($pf, $args['name'], $args['ticker'], $args['currency'], $args['isin']);
	break;

case 'rm-line':
	isset($args['ticker']) || fatal("Missing argument for rm-line: ticker\n");
	rm_line($pf, $args['ticker']);
	break;

case 'edit-line':
	isset($args['ticker']) || fatal("Missing argument for edit-line: ticker\n");
	edit_line($pf, $args['ticker'], $args);
	break;

case 'ls-lines':
	ls_lines($pf);
	break;

case 'add-tx':
	isset($args['ticker']) || fatal("Missing argument for add-tx: ticker\n");
	if(isset($args['buy']) && isset($args['sell'])) fatal("Too many arguments for add-tx: buy and sell\n");
	$nargs = 0;

	if(isset($args['sell']) && $args['sell'] === 'all') {
		$args['sell'] = aggregate_tx($pf, [])[$args['ticker']]['qty'];
	}

	if(isset($args['buy']) || isset($args['sell'])) {
		++$nargs;
		$qty = floatval($args['buy'] ?? -$args['sell']);

		if($qty > 0 && isset($args['total'])) {
			$args['total'] = -abs(floatval($args['total']));
		}
	}
	if(isset($args['price'])) {
		++$nargs;
		$price = floatval($args['price']);
	}
	if(isset($args['fee'])) {
		++$nargs;
		$fee = floatval($args['fee']);
	}
	if(isset($args['total'])) {
		++$nargs;
		$total = floatval($args['total']);
	}

	if($nargs !== 3) fatal("add-tx: must have three of: (buy|sell), price, fee, total\n");

	if(!isset($qty)) {
		$qty = -($total + $fee) / $price;
	} else if(!isset($price)) {
		$price = -($total + $fee) / $qty;
	} else if(!isset($fee)) {
		$fee = -$qty*$price - $total;
	}

	add_tx($pf, $args['ticker'], $qty, $price, $fee, $args['date'] ?? 'now');
	break;

case 'rm-tx':
	if($args === []) fatal("rm-tx: expecting at least one txid.\n");
	rm_tx($pf, $args);
	break;

case 'ls-tx':
	ls_tx($pf, $args);
	break;

case 'plot-gains':
	plot_gains($pf, $args['start'] ?? '-2 years', $args['end'] ?? 'now', $args['absolute'] ?? true);
	break;

case 'plot-lines':
	plot_lines($pf, $args['start'] ?? '-2 years', $args['end'] ?? 'now', $args['absolute'] ?? true, $args['total'] ?? true);
	break;

case 'get-quote':
	if(!isset($args['ticker'])) fatal("Missing argument ticker\n");
	$q = get_quote($pf, $args['ticker'], $args['at'] ?? 'now');
	if($q === null) {
		echo "NULL\n";
	} else {
		printf("%.2f\n", $q);
	}
	break;

case 'quotes-to-gnucash':
	if(count($args) !== 1 || !isset($args[0])) {
		fatal("quotes-to-gnucash expects exactly one unnamed argument\n");
	}
	$d = load_gnucash($args[0]);
	insert_gnucash_quotes($pf, $d);
	save_gnucash($args[0], $d);
	break;

case 'version':
case '-v':
case '--version':
	fprintf(STDERR, "pfm version %s, build %s\n\n", trim(file_get_contents(__DIR__.'/../ext/version')), trim(file_get_contents(__DIR__.'/../ext/build-datetime')));
	fwrite(STDERR, "This program is free software. It comes without any warranty, to the\nextent permitted by applicable law. You can redistribute it and/or\nmodify it under the terms of the Do What The Fuck You Want To Public\nLicense, Version 2, as published by Sam Hocevar. See\nhttp://sam.zoy.org/wtfpl/COPYING for more details.\n\n");
	fprintf(STDERR, "Portfolio file: %s, override with PFM_PORTFOLIO_FILE or XDG_DATA_HOME.\n", $pfp);
	fprintf(STDERR, "Cache directory: %s, override with XDG_CACHE_HOME.\n", get_paths()['cache-home']);
	fprintf(STDERR, "Config directories: %s, override with XDG_CONFIG_DIRS or XDG_CONFIG_HOME.\n", implode(':', get_paths()['configs']));
	fwrite(STDERR, "\nRun `pfm help` for a list of available commands.\n");
	break;

case 'help':
case 'h':
case '-h':
case '--help':
	fwrite(STDERR, "Available commands:\n");
	fwrite(STDERR, "pfm [version]\n");
	fwrite(STDERR, "pfm help|h\n");
	fwrite(STDERR, "pfm status|s [at:<date>]\n");
	fwrite(STDERR, "pfm perf|p [at:<date>] [columns:default|days|weeks|months|years]\n");
	fwrite(STDERR, "pfm add-line name:<name> ticker:<ticker> currency:<currency> isin:<ISIN>\n");
	fwrite(STDERR, "pfm rm-line ticker:<ticker>\n");
	fwrite(STDERR, "pfm edit-line ticker:<ticker> [<field1>:<newval1>] [<field2>:<newval2>]…\n");
	fwrite(STDERR, "pfm ls-lines\n");
	fwrite(STDERR, "pfm add-tx ticker:<ticker> [sell:<quantity>|all] [buy:<quantity>] [price:<unit-price>] [fee:<fee>] [total:<total>] [date:<date>]\n");
	fwrite(STDERR, "pfm rm-tx <txid>...\n");
	fwrite(STDERR, "pfm ls-tx [ticker:<ticker>] [before:<date>] [after:<date>]\n");
	fwrite(STDERR, "pfm get-quote ticker:<ticker> [at:<date>]\n");
	fwrite(STDERR, "pfm plot-gains [start:<date>] [end:<date>] [absolute:1|0]\n");
	fwrite(STDERR, "pfm plot-lines [start:<date>] [end:<date>] [absolute:1|0] [total:1|0]\n");
	fwrite(STDERR, "pfm quotes-to-gnucash <file.gnucash>\n");
	break;

default:
	fprintf(STDERR, "pfm: unknown command %s\n", $cmd);
	die(2);

}

save_pf($pf, $pfp);
fwrite(STDOUT, "\r");
