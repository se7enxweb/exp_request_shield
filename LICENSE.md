# License

## exp_request_shield

Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

The full text of the GNU General Public License v2.0 is in [LICENSE](LICENSE).

This covers everything in this extension except the files listed below: the
bootstrap, the classes, the admin module and its templates and stylesheet, the
settings, the command line wrappers, the build files, the default main rule
file `rules/request-shield.rules`, the documentation and the translations.

## Bundled software under the MIT License

These files are not covered by the GPL notice above. They keep their own
license, which is compatible with the GPL v2 and allows them to be distributed
with this extension.

| Files | Origin |
|---|---|
| `lib/requestshield/*.php` | request-shield, built unchanged from its release tag ([doc/library.md](doc/library.md)) |
| `lib/requestshield/php74/*.php` | the same files with their syntax downgraded to PHP 7.4 by `build/rector-php74.php` |
| `rules/exponential.rules`, `rules/exponential-admin-uri.rules`, `rules/exponential-admin-host.rules` | request-shield's `examples/exponential/`, copied unchanged |

Their license, [lib/requestshield/LICENSE](lib/requestshield/LICENSE):

> MIT License
>
> Copyright (c) 2026 JAC Systeme GmbH (CJW Network)
>
> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software and associated documentation files (the "Software"), to deal
> in the Software without restriction, including without limitation the rights
> to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
> copies of the Software, and to permit persons to whom the Software is
> furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all
> copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
> OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
> SOFTWARE.

Project: https://github.com/cjw-network/request-shield
