# linkvalidator2_httpstatus_io

**Experimental, for trying out only.** Extension for `linkvalidator2` that asks the
[httpstatus.io API](https://httpstatus.io/api/documentation) about external URLs the own
request could not decide, e.g. because a site behind Cloudflare answers with 403.

## How it works

- `HttpStatusIoChecker` is a checker for the type `url` with the tag priority `-100`, so it comes
  after `ExternalUrlChecker` (priority 0) in the chain of linkvalidator2.
- The chain only reaches it if the own check ended with `unknown`. Reachable URLs (`ok`) and
  clear 404/410 never use the API.
- It sends `POST https://api.httpstatus.io/v1/status` (header `X-Billing-Token`) and classifies the
  status code of the last entry of the redirect chain: 2xx ok, 404/410 broken, anything else unknown.
- **Trial phase:** only URLs of the domains in `ALLOWED_DOMAINS` (`linkedin.com`, `httpbin.org`) and their subdomains are sent to the API (free quota of
  500 requests, one call per second), every other host is skipped.
- Answers are stored per URL as files in `var/statuscode_io/` (`cacheLifetime` hours), so flushing
  the TYPO3 caches does not lose them. Calls are spaced at least one second apart.
- It skips itself without an access key and whenever the API gives no usable answer (error,
  rate limit 429, quota, timeout). The result and the details of the own check stay then.

## Settings

Extension configuration of `linkvalidator2_httpstatus_io`: `accessKey` (from the plan of
httpstatus.io), `userAgent` (preset of the API, default `chrome`), `timeout` and `cacheLifetime`.

## Limits

One API call per URL, synchronous. Not meant for large installations.
