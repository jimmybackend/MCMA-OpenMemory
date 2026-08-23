# Memory Taxonomy

MCMA-OpenMemory uses two independent classification axes:

1. **Cognitive layer** — what kind of memory is this?
2. **Temperature** — how active is this memory right now?

A third dimension, **scope**, identifies where the memory belongs.

## Directory model

A provider may expose the logical structure as directories:

```text
memories/
├── hot/
├── warm/
├── cold/
└── frozen/
```

Each temperature uses the same cognitive layer structure:

```text
00-system/
10-self/
20-working/
30-episodic/
40-semantic/
50-procedural/
60-relational/
70-preferences/
80-goals/
90-projects/
95-world-model/
99-meta/
```

This lets a reader infer the intended meaning of a path before decrypting every object.

## Cognitive layers

### `00-system`

Memory about the memory system itself.

Examples:

- routing rules;
- agent capabilities;
- memory policies;
- permissions;
- configuration concepts;
- protocol/version information.

### `10-self`

Persistent model of the user, agent or identity being served.

Examples:

- stable identity facts;
- long-lived profile concepts;
- role and responsibility descriptions;
- self-model information.

### `20-working`

Short-lived active context required for current work.

Examples:

- current task state;
- active assumptions;
- temporary calculations;
- immediate conversation/project context.

Working memory will often be Hot and may later be consolidated into another layer.

### `30-episodic`

Events and experiences with temporal context.

Examples:

- what happened;
- when it happened;
- session outcomes;
- incidents;
- tests and observations;
- decisions made during a particular event.

### `40-semantic`

Facts, concepts and generalized knowledge.

Examples:

- definitions;
- architecture concepts;
- domain knowledge;
- learned facts;
- conceptual relationships.

### `50-procedural`

Knowledge of how to perform a task.

Examples:

- runbooks;
- repair procedures;
- deployment steps;
- commands;
- tested methods;
- workflows;
- repeatable solutions.

### `60-relational`

Relationships between people, agents, systems or entities.

Examples:

- team relationships;
- service dependencies;
- entity relationships;
- agent collaboration structures.

### `70-preferences`

Preferences, criteria and recurring choices.

Examples:

- formatting preferences;
- working style;
- technical preferences;
- interaction preferences;
- recurring decision criteria.

### `80-goals`

Intentions, plans and desired future states.

Examples:

- objectives;
- milestones;
- pending work;
- project direction;
- future experiments.

### `90-projects`

Project-specific state that benefits from a durable project boundary.

Examples:

- project architecture;
- implementation state;
- component inventory;
- current design decisions;
- project constraints.

### `95-world-model`

Structured knowledge about external environments and systems.

Examples:

- infrastructure topology;
- external APIs;
- cloud resources;
- server roles;
- service ecosystems;
- known external constraints.

### `99-meta`

Memory about memory.

Examples:

- consolidation records;
- summaries of multiple memories;
- confidence or quality notes;
- conflicts;
- provenance;
- links between memories;
- reclassification decisions;
- temperature transition reasons.

## Scope

After cognitive layer, a scope identifies ownership/context.

Recommended common scopes:

```text
global/
user/
project/
agent/
session/
system/
```

Examples:

```text
memories/hot/90-projects/project/mcma/architecture/<memory-id>.mcma
memories/warm/50-procedural/project/mcma/deployment/<memory-id>.mcma
memories/cold/40-semantic/global/ai/memory-systems/<memory-id>.mcma
memories/frozen/30-episodic/project/mcma/history/<memory-id>.mcma
```

## Temperature model

### Hot

Active memory with a high probability of near-term retrieval.

Typical properties:

- included in normal routing;
- high index priority;
- recently used or explicitly pinned;
- immediately available.

### Warm

Relevant memory that is not continuously active.

Typical properties:

- retrieved when topic/scope matches;
- lower priority than Hot;
- still part of normal memory search.

### Cold

Long-term memory.

Typical properties:

- retrieved on demand;
- usually discovered through indexes, RAG or explicit search;
- not routinely injected into context.

### Frozen

Preserved historical memory outside normal automatic retrieval.

Typical properties:

- explicit or deep retrieval only;
- archival preservation;
- candidate for later reactivation;
- useful for reconstruction, audit or history.

## Temperature transitions

A memory may cool:

```text
Hot → Warm → Cold → Frozen
```

or reactivate:

```text
Frozen → Cold → Warm → Hot
```

A temperature transition should not change cognitive layer unless a separate reclassification is also justified.

## Logical path grammar

Recommended general form:

```text
memories/{temperature}/{cognitive-layer}/{scope}/{topic}/{subtopic...}/{memory-id}.mcma
```

Recommended URI form:

```text
mcma://{temperature}/{cognitive-layer}/{scope}/{topic}/{subtopic...}/{memory-id}
```

## File names and privacy

Human-readable filenames are convenient but can leak subject matter even when ciphertext is secure.

Privacy-oriented deployments should prefer opaque IDs:

```text
mem_01K34WQ8VGC7TJ6P6KJQ.mcma
```

A protected index can map that ID to concepts, entities, summaries and relationships.

## Why numbered layers?

The numeric prefixes provide:

- stable ordering across filesystems and providers;
- easy visual scanning;
- room for future intermediate layers;
- unambiguous machine parsing;
- predictable documentation references.

The numbers are identifiers, not a claim that biological memory is literally divided into numbered folders. The taxonomy is inspired by cognitive memory concepts but engineered for software interoperability.
