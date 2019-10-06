# pfm

A simple command-line stock portfolio manager.

Released under the WTFPLv2 license.

# Demo

[![asciicast](https://asciinema.org/a/s75fcKuiVPxxtfFV7K6bqN66h.svg)](https://asciinema.org/a/s75fcKuiVPxxtfFV7K6bqN66h)

# Dependencies

* PHP ≥ 7 (cli only)
* Gnuplot (optional; for plot commands)
* Firejail (optional)

# Install

~~~
make
sudo make install PREFIX=/usr
sudo ln -s /usr/bin/firejail /usr/local/bin/pfm
~~~

# Usage

~~~
Available commands:
pfm [version]
pfm help|h
pfm status|s [at:<date>]
pfm perf|p [type:irr|pnl] [at:<date>] [columns:default|days|weeks|months|years] [rows:all|open|ticker1,ticker2…] [sort:perf|weight|ticker]
pfm add-line name:<name> ticker:<ticker> currency:<currency> isin:<ISIN>
pfm rm-line ticker:<ticker>
pfm edit-line ticker:<ticker> [<field1>:<newval1>] [<field2>:<newval2>]…
pfm ls-lines
pfm add-tx ticker:<ticker> [sell:<quantity>|all] [buy:<quantity>] [price:<unit-price>] [fee:<fee>] [total:<total>] [date:<date>]
pfm rm-tx <txid>...
pfm ls-tx [ticker:<ticker>] [before:<date>] [after:<date>]
pfm get-quote ticker:<ticker> [at:<date>]
pfm plot-gains [start:<date>] [end:<date>] [absolute:1|0]
pfm plot-lines [start:<date>] [end:<date>] [absolute:1|0] [lines:all|none|ticker1,ticker2,…] [total:1|0] [benchmark:1|0]
pfm quotes-to-gnucash <file.gnucash>
pfm prune-history [tickers:<ticker>,…] [except:<ticker>,…] [from:<date>] [to:<date>]
pfm set-benchmark [<ticker1>:<weight1>] [<ticker2>:<weight2>] …
~~~

# About transactions

Transaction total is calculated using the transaction equation:

~~~
total = (-quantity) * price - fee
~~~

Where quantity is positive for buying, negative for selling. When
buying, set user-supplied `total` as negative.

When using `add-tx`, exactly three values from total, quantity, price
and fee must be supplied, for example:

~~~
# Calculated tx fee of 2
pfm add-tx ticker:FOO buy:10 price:5 total:52

# Calculated price of 5
pfm add-tx ticker:FOO buy:10 fee:2 total:52
~~~

Dividends and management fees can be input as follows:

~~~
# Gain a €12.34 dividend from FOO stocks, as cash
pfm add-tx ticker:FOO sell:0 price:0 fee:-12.34

# Gain a €45.67 dividend from FOO stocks, automatically reinvested
pfm add-tx ticker:FOO buy:0.1234 fee:-45.67 total:0

# Management fee of €1.23
pfm add-tx ticker:FOO sell:0.4567 fee:1.23 total:0
~~~

Stock splits or merges:

~~~
# Own 10 shares of FOO, has a 1:5 split
pfm add-tx ticker:FOO buy:40 price:0 total:0

# Own 100 shares of FOO, has a 1:7 split
pfm add-tx ticker:FOO sell:2 price:12.34 fee:0
pfm add-tx ticker:FOO sell:84 price:0 total:0
~~~
