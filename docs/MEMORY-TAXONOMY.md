# MCMA Memory Taxonomy

MCMA 1.0 keeps cognitive meaning, scope and temperature as independent dimensions.

## 1. Cognitive layer — what kind of memory is this?

```text
00-system
10-self
20-working
30-episodic
40-semantic
50-procedural
60-relational
70-preferences
80-goals
90-projects
95-world-model
99-meta
```

### 00-system
Memory about MCMA configuration, policies, formats, routing and capabilities.

### 10-self
Persistent identity/self-model information.

### 20-working
Short-lived active context for current work.

### 30-episodic
Events and experiences with temporal context.

### 40-semantic
Facts, concepts and generalized knowledge.

### 50-procedural
Runbooks, methods, commands and repeatable procedures.

### 60-relational
Relationships among people, agents, systems and entities.

### 70-preferences
Recurring choices, interaction preferences and criteria.

### 80-goals
Objectives, plans and intended future states.

### 90-projects
Durable project-specific state, architecture and decisions.

### 95-world-model
Structured knowledge about external systems and environments.

### 99-meta
Memory about memory: provenance, conflicts, summaries, reclassification and lifecycle reasoning.

## 2. Scope — where does it belong?

Common scopes include:

```text
global
user
project
agent
session
system
```

Implementations may add scopes while preserving interoperability metadata.

## 3. Temperature — how active is it now?

```text
HOT
WARM
COLD
FROZEN
```

### HOT
Active and likely to be retrieved soon.

### WARM
Relevant but not continuously active.

### COLD
Long-term memory usually found through indexes or explicit retrieval.

### FROZEN
Historical preservation outside normal automatic retrieval.

A memory can reactivate in either direction. Temperature is not privacy.

## Virtual views, not permanent folders

Historical prototypes stored objects under paths such as `memories/hot/...`. MCMA 1.0 treats that as a possible human-facing or compatibility view, not canonical permanent identity.

Conceptually:

```text
stable object
   │
   ├── cognitive layer
   ├── scope
   ├── temperature
   └── logical aliases
```

Indexes can render views such as:

```text
HOT/
WARM/
COLD/
FROZEN/
Projects/
People/
Topics/
Dates/
```

without requiring the encrypted object to move physically.


## Explicit user memories

A deliberate user instruction such as `Guarda esto: ...` can create a canonical classified object while preserving the same independent taxonomy dimensions.

Example:

~~~text
memory://user/projects/mcma-semantic-precision-3d8e39c0c421
  cognitive_layer: 90-projects
  scope: project
  temperature: hot
  maturity: confirmed
~~~

The canonical route is derived by MCMA, not supplied by a language model. The encrypted content preserves the user's source text plus the normalized durable representation. Implementations may maintain a separate knowledge/semantic recovery reference that points back to this canonical object.

This keeps **where/what the memory is** separate from **how it is retrieved**.

## Logical references

Logical references are independent of temperature, for example:

```text
memory://identity/profile
memory://projects/mcma
memory://topics/security
```

The authorized index resolves the reference to a stable object ID and physical locator.

## Physical storage

Private libraries may use opaque/hash-based storage:

```text
objects/7a/21/7a21....mcma
```

Human meaning remains in authorized encrypted metadata/indexes rather than filenames.

## Why numbered cognitive layers?

The numeric prefixes provide stable ordering and identifiers for interoperability. They are a software taxonomy, not a claim that biological memory is literally organized as numbered folders.
