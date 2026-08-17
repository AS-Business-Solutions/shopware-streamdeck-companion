# 1.1.0
- Added: "Test connection" button in the plugin configuration – checks the API key store, the metrics read path and, when a key is entered, a live round-trip over the companion API
- Fixed: uninstalling with "delete all data" no longer leaves API keys behind; after a reinstall the key management starts empty again

# 1.0.0
- First release in the Shopware Store
- Stable feature set: metric endpoints (daily revenue, latest order, top order & top product, average order value, revenue trend, revenue per hour/month), API key management right in the admin, live shop status, plus configurable revenue exclusions and net/gross values throughout – every time-based metric is time-zone safe (DST-aware)
- Supports Shopware 6.7

# 0.6.1
- Changed: narrowed supported Shopware version to 6.7 (6.6 is no longer supported)

# 0.6.0
- Added: revenue-by-month endpoint (current year, January → current month) for the Stream Deck dial's yearly view; buckets by the configured time zone (DST-safe) and applies the same revenue exclusions and net/gross handling as the other metrics

# 0.5.0
- Added: configurable revenue exclusions – line items that are not real revenue (e.g. virtual surcharges) can be removed from every revenue metric by product selection or label list; the order itself still counts
- Changed: all revenue endpoints now always return both net and gross values (the "mode" parameter is gone)
- Fixed: the revenue charts (per day/hour) now group by the configured time zone instead of UTC – early-morning revenue lands in the correct day/hour (DST-safe)

# 0.4.1
- Fixed: orders placed in the early hours (local time) were missing from the daily metrics (revenue and count) – the day boundary is now computed correctly in the configured time zone

# 0.4.0
- Fixed: the plugin configuration now shows up in the administration (the "Configure" entry)
- Fixed: the API key management now loads correctly instead of being stuck on a spinner

# 0.3.0
- Initial release
- API key management right in the plugin configuration (generate, list, revoke) – no console access required
- Metric endpoints for the Stream Deck plugin: daily revenue, latest order, top order & top product, average order value, revenue trend (7–60 days), revenue per hour
- Live shop status: production mode, system health and overdue scheduled tasks
- Configurable order and payment state filters for revenue analytics
- Authentication via a shop-bound API key (SHA-256), no external license server
- Compiled administration assets shipped: ready right after installation, no extra build step
