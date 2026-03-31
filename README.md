# Arbitrage Dopamine Machine

## Ano at bakit

I got tired of having multiple tabs so I made this. You have any idea how annoying it is when changing tabs each time I pause my Old School Runescape fishing loops? It's frustrating. Tapos I make the wrong clicks pa.

Ergo, I made this app. It connects to Binance and CoinsPH public APIs. It checks the bookTicker endpoints, gathers specific tickers, then compares them for better arbitrage use. It compares Ask and Bid prices in a two-way manner. When it hits a profit, it puts an appropriate emoji.

Polling interval and profit threshold are at hardcoded lines and can be edited manually if you so please:

```
POLL_INTERVAL_MS = 5000
PROFIT_THRESHOLD_PCT = 0.50
```

An emoji shows up if it goes past the profit threshold. Otherwise it just shows an X.

## Email Alerts (SMTP)

There is now optional SMTP email alerting with `.env` config.

How it works:
- App checks a **separate alert threshold** (`ALERT_THRESHOLD_PCT`) independent from the on-screen emoji threshold.
- When any asset direction reaches or exceeds that alert threshold, it sends **one email alert** containing current spreads and emojis.
- It will not keep spamming while the same threshold breach is still active.
- It has a cooldown guard (`ALERT_COOLDOWN_SECONDS`) before another send is allowed (default: 300 seconds / 5 minutes).
- It has a cooldown guard of 2 minutes before another send is allowed.
- Once spreads go below threshold again, alert state resets and it can alert again on the next breach.

Set these in `.env` (you can copy from `.env.example`):

```
ALERTS_ENABLED=false
ALERT_THRESHOLD_PCT=1.25
ALERT_COOLDOWN_SECONDS=300
SMTP_HOST=smtp.example.com
SMTP_PORT=465
SMTP_USERNAME=your_username
SMTP_PASSWORD=your_password
SMTP_FROM_EMAIL=alerts@example.com
SMTP_TO_EMAIL=recipient@example.com
```

In the UI, there is a visible switch/status row: `Email alerts: Enabled/Disabled`.

## Installation

You need XAMPP for this, specifically XAMPP 8.2. Get it from the Apache Friends official website. Then place the index.php in your XAMPP's htdocs folder. Hence `c:/xampp/htdocs/index.php` . Then turn on your Apache server. Then visit `http://localhost` . Serve hot.

If your environment is Linux, you'll need PHP 8.2 and Apache, but frankly I haven't tested it. Try it yourself. Kaya mo na yan, malaki ka na.

## FAQ

### Why Windows?

Too lazy to put Steam and hence OSRS on my Ubuntu. I made this on my 10 year old laptop. Heck I could imagine it would work on a phone if it only had PHP 8.2. I hear Termux lets you do that. But I'm too lazy to test.

### Can I distribute this?

Yeah. It's free. Host it if you want, I frankly don't care. Check out the `LICENSE.md` file. Stick with that and you're good.

## License

This was made with GNU General Public License v3. There's a license doc attached to this repo. You know how to read, otherwise you wouldn't be here.
