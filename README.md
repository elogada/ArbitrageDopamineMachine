

\# Arbitrage Dopamine Machine



\## Ano at bakit



I got tired of having multiple tabs so I made this. You have any idea how annoying it is when changing tabs each time I pause my Old School Runescape fishing loops? It's frustrating. Tapos I make the wrong clicks pa.



Ergo, I made this app. It connects to Binance and CoinsPH public APIs. It checks the bookTicker endpoints, gathers specific tickers, then compares them for better arbitrage use. It compares Ask and Bid prices in a two-way manner. When it hits a profit, it puts an appropriate emoji.



Polling interval and profit threshold are at hardcoded lines and can be edited manually if you so please:



```

POLL\_INTERVAL\_MS = 5000

PROFIT\_THRESHOLD\_PCT = 0.50

```



An emoji shows up if it goes past the profit threshold. Otherwise it just shows an X.



\## Installation



You need XAMPP for this, specifically XAMPP 8.2. Get it from the Apache Friends official website. Then place the index.php in your XAMPP's htdocs folder. Hence `c:/xampp/htdocs/index.php`. Then turn on your Apache server. Then visit localhost. Serve hot.



If your environment is Linux, you'll need PHP 8.2 and Apache, but frankly I haven't tested it. Try it yourself. Kaya mo na yan, malaki ka na.



\## FAQ



\### Why Windows?



Too lazy to put Steam and hence OSRS on my Ubuntu. I made this on my 10 year old laptop. Heck I could imagine it would work on a phone if it only had PHP 8.2. I hear Termux lets you do that. But I'm too lazy to test.



\### Can I distribute this?



Yeah. It's free. Host it if you want, I frankly don't care. Check out the `LICENSE.md` file. Stick with that and you're good.



\## License



This was made with GNU General Public License v3. There's a license doc attached to this repo. You know how to read, otherwise you wouldn't be here.

