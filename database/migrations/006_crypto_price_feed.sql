-- Live market prices for crypto assets (e.g. CoinGecko). Holdings remain internal records.

ALTER TABLE crypto_assets
    ADD COLUMN feed_id VARCHAR(80) NULL AFTER name;             -- provider coin id, e.g. "bitcoin"

ALTER TABLE crypto_price_history
    MODIFY source ENUM('manual','simulator','initial','feed') NOT NULL;
