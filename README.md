# pfm

A simple command-line stock portfolio manager.

Released under the WTFPLv2 license.

# Dependencies

* PHP ≥ 7 (cli)
* Gnuplot

# Screenshots

![](./img/perf-relative.png) ![](./img/perf-absolute.png)

~~~
Silmeria ~ % pfm
  Tkr |    Price |  Quantity |  Money in |     Value |      Gain |  %Wgt |  Perf
------+----------+-----------+-----------+-----------+-----------+-------+------
 500H |          |    0.0000 |    -26.80 |           |     26.80 |       |      
  CEU |   182.60 |   15.0000 |   2772.65 |   2739.00 |    -33.65 | 17.31 | -1.21
  CW8 |          |    0.0000 |   -372.51 |           |    372.51 |       |      
 ESEH |   100.81 |   58.0000 |   5849.15 |   5846.69 |     -2.46 | 36.95 | -0.04
  MTD |   171.63 |    2.9768 |    500.00 |    510.92 |     10.92 |  3.23 |  2.18
 SMAE |   121.69 |   24.0000 |   2926.52 |   2920.56 |     -5.96 | 18.46 | -0.20
  UST |    15.77 |  115.9520 |   1950.00 |   1827.98 |   -122.02 | 11.55 | -6.26
  WLD |   148.03 |   13.3658 |   2051.06 |   1978.47 |    -72.59 | 12.50 | -3.54
------+----------+-----------+-----------+-----------+-----------+-------+------
  TOT |          |           |  15650.07 |  15823.63 |    173.56 |       |  1.11
~~~

~~~
Silmeria ~ % pfm perf
  Ticker |   MtD |   May |   Apr |   Mar |   YtD |  2015 |  2014 |  2013 |   All
---------+-------+-------+-------+-------+-------+-------+-------+-------+------
    500H | -0.36 |  1.63 |       |       |       |       |       |       |      
     CEU | -1.21 |       |       |       | -1.21 |       |       |       | -1.21
     CW8 |  0.27 |  3.40 |  0.46 |  0.15 |       |       |       |       |      
    ESEH | -0.04 |       |       |       | -0.04 |       |       |       | -0.04
     MTD |  0.28 |  1.07 | -1.03 |  0.48 |  2.18 |       |       |       |  2.18
    SMAE | -0.20 |       |       |       | -0.20 |       |       |       | -0.20
     UST | -1.71 |  7.46 | -4.55 |  0.54 | -7.26 |  1.25 |       |       | -6.26
     WLD | -1.26 |  3.44 |  0.18 |  0.95 | -3.19 | -0.37 |       |       | -3.54
---------+-------+-------+-------+-------+-------+-------+-------+-------+------
   TOTAL | -0.54 |  3.56 | -0.46 |  0.43 |  1.00 |  0.44 |       |       |  1.11
~~~

# Usage

## Status view (`status`, default)

Parameters:

* `at:<date>`: see a past snapshot of the portfolio. Use a
  `strtotime()`-parseable format. For example, `at:2016-01-01` will
  show the porfolio (and the prices) as they were on january 1, 2016.

Columns:

* `Tkr`: the line ticker
* `Price`: the price of the stock
* `Quantity`: number of stocks you own
* `Money in`: total amount of money you spent on this stock, calculated using the **money in equation**:

  ~~~
  money_in = buy_qty * buy_price + fees - sell_qty * sell_price
  ~~~
  
* `Value`: price * quantity
* `Gain`: value - money_in
* `%Wgt`: weight of this line in your portfolio
* `Perf`: overall performance of this line

## Performance view (`perf`)

Parameters:

* `at:<date>`: see above.

Columns:

* `Ticker`: the line ticker
* `MtD`: performance of this line month-to-date (ie from beginning of the month to today)
* `May`, `Apr`, `Apr`: performance of this line over the last 3 months
* `YtD`: performance of this line year-to-date (ie from beginning of the year to today)
* `2015`, `2014`, `2013`: performance of this line over the last 3 years
* `All`: overall performance of this line

## Performance graph (`plot-perf`)

~~~
Silmeria ~ % pfm plot-perf start:2016-01-01 overlays:CW8U
~~~

Parameters:

* `start:<date>`
* `end:<date>`
* `absolute:0|1`: default is relative (0), absolute will show buy/sell operations and overall portfolio value
* `overlays:<tickers>`: comma-separated list of tickers to display, only works in relative mode
* `raw:0|1`: if `1`, don't invoke `gnuplot` and print the raw data, useful if you want to use another tool to generate a chart

## Transactions view (`ls-tx`)

Parameters:

* `ticker:<ticker>`: only show transactions on this line
* `before:<date>`: only show transactions before this date (`strtotime()`-formatted)
* `after:<date>`: only show transactions after this date (`strtotime()`-formatted)

## Add transaction (`add-tx`)

~~~
Silmeria ~ % pfm add-tx ticker:SMAE date:2016-06-01 buy:24 price:121.78 total:2926.52
~~~

Parameters:

* `ticker:<ticker>`: required
* `date:<date>`: optional, use `now` as default (`strtotime()`-formatted)
* `buy:<qty>` or `sell:<qty>`: optional
* `price:<unit-price>`: optional
* `fee:<fee>`: optional
* `total:<total>`: optional

Must have exactly three of: `buy` (or `sell`), `price`, `fee` and
`total`. The fourth value will be computed using the **transaction
equation**:

~~~
total = (-quantity) * price - fee
~~~

Where quantity is positive for buying, negative for selling. When
buying, set user-supplied `total` as negative.

## Remove transaction(s) (`rm-tx`)

~~~
Silmeria ~ % pfm rm-tx 7 10 12
~~~

Get transaction IDs using `ls-tx`.

## Lines view (`ls-lines`)

~~~
Silmeria ~ % pfm ls-lines        
                                      Name |  Tkr | Cur |   Yahoo |         ISIN
-------------------------------------------+------+-----+---------+-------------
           AMUNDI ETF MSCI WORLD UCITS ETF |  CW8 | EUR |  CW8.PA | FR0010756098
~~~

## Add line (`add-line`)

~~~
Silmeria ~ % pfm add-line ticker:CW8 yahoo:CW8.PA currency:EUR isin:FR0010756098 'name:AMUNDI ETF MSCI WORLD UCITS ETF'
~~~

Parameters:

* `name:<name>`: required
* `ticker:<ticker>`: required, must be unique
* `currency:<currency-code>`: required, but unused at the moment (TODO)
* `yahoo:<yahoo>`: optional, Yahoo Finance ticker (used for quotes & price history)
* `isin:<isin>`: optional, ISIN code of the stock (used for quotes & price history)

## Remove line (`rm-line`)

~~~
Silmeria ~ % pfm rm-line ticker:CW8
~~~
