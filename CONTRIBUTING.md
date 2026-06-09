# Contributing

Thanks for your interest in improving the Velocity Fleet Laravel bridge! Contributions of all sizes are welcome.

## Getting started

```bash
git clone https://github.com/chrisjohnleah/velocity-fleet-api-laravel.git
cd velocity-fleet-api-laravel
composer install
composer check   # lint + static analysis + tests
```

## Ground rules

- **Tests are required.** Every change must be covered by a Pest test (orchestra/testbench). Tests must not hit the network.
- **Keep it green.** `composer check` must pass: Pint (code style), Larastan at `max`, and the full Pest suite.
- **Match the conventions.** This package only *wires* the core SDK into Laravel — HTTP, auth, refresh, and DTOs belong in [`chrisjohnleah/velocity-fleet-api`](https://github.com/chrisjohnleah/velocity-fleet-api), not here.
- **One focused change per PR.** Update [CHANGELOG.md](CHANGELOG.md) under `Unreleased`.

## Reporting bugs / requesting features

Open an issue with the relevant template. For security vulnerabilities, **do not** open a public issue — see [SECURITY.md](SECURITY.md).
