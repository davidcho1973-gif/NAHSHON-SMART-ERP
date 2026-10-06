OAuth security tests generate ephemeral RSA keys in memory through OpenSSL.
The keys belong only to the isolated synthetic test application and are never
written to the repository, a production secret store, or a deployed environment.
No operational key or OAuth client is created by code deployment.
