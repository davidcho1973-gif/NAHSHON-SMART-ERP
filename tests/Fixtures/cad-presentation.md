# Public presentation-resource fixture

`cad-presentation.dwg` is the unmodified empty drawing from
[ACadSharp v3.8.0](https://github.com/DomCR/ACadSharp/blob/3a1c66b9932f9e2dbfbbf3f03660c97d52e49b02/samples/sample_base/empty.dwg).
It contains default CAD presentation resources and no user drawing.

Copyright (c) 2021 Albert Domenech. MIT license; the full notice is retained in
`services/cad-converter/ACadSharp.LICENSE`.

SHA256: `ac65283f3e078a8828e0df594b15a0f54498d04b0c88c85476278c8d3bc1acfb`.

The fixture is necessary because the dependency's writer omits VisualStyle
objects and its DXF reader ignores their fields. Re-creating this fixture with
that same writer would accidentally remove the behavior the test must cover.
