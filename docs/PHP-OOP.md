# PHP OOP Architecture

MCMA production PHP uses object-oriented application boundaries.

## Rule

Business/runtime logic belongs in classes or interfaces.

The only intentionally minimal procedural surfaces are executable/bootstrap entrypoints required to load and start the object graph.

~~~text
apps/cli/mcma
    ↓
CliApplication
    ├── ProviderFactory
    ├── StorageFactory
    ├── Library
    ├── KnowledgeService
    ├── SemanticIndexService
    ├── AskService
    ├── Librarian
    └── SecurityAgent
~~~

## CLI

`apps/cli/mcma` does not contain command logic or global helper functions.

It only loads the bootstrap and runs:

~~~text
CliApplication
~~~

`CliApplication` owns argument parsing, command dispatch, input/output helpers and orchestration.

`ProviderFactory` owns selection/creation of embedding and generation providers.

## Production rule enforced by CI

The OOP conformance test scans:

~~~text
packages/core/src
packages/connectors
~~~

and rejects named global function declarations.

Reference/compatibility scripts and tests are not treated as production application architecture. They may remain script-oriented where that improves conformance testing or historical compatibility.

## Why

This keeps MCMA easier to extend with:

- new StorageAdapter implementations;
- new GenerationProvider implementations;
- new EmbeddingProvider implementations;
- HTTP/API frontends;
- dependency injection;
- isolated tests;
- future package/autoload improvements.

Adding an HTTP application later should instantiate the same services instead of duplicating CLI logic.
