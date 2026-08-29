# Identity and the Living Profile

## The user as a living document

MCMA treats a person as an evolving document graph rather than a rigid database row.

The canonical entry point is:

~~~text
memory://identity/profile
~~~

with a corresponding document such as:

~~~text
identity/profile.mcma
~~~

## Purpose of profile.mcma

profile.mcma represents the best current consolidated description of the owner.

It answers: Who is this person now, according to authorized and sufficiently trusted memory?

It should not contain the person's entire historical event stream.

## Suggested sections

~~~json
{
  "identity": {},
  "contact": {},
  "communication": {},
  "preferences": {},
  "professional": {},
  "interests": {},
  "important_dates": [],
  "relationships": [],
  "references": {},
  "notes": [],
  "extensions": {}
}
~~~

This is illustrative, not a rigid database schema.

Clients must allow evolution beyond fields known today.

## Human context that relational tables model poorly

MCMA should naturally preserve information such as:

- how the person prefers to be addressed;
- how they prefer technical explanations;
- who matters around an important date;
- relationships and context around those relationships;
- work habits;
- project priorities;
- decisions and reasons;
- dislikes or interaction boundaries;
- contextual notes that do not fit a predefined column.

## Current state vs history

The current profile remains compact.

History is preserved separately.

~~~text
profile.mcma
     │
     ├── current consolidated state
     │
     └── references
             ↓
        history objects
~~~

A compatible client should be able to answer both who the person is now and how a preference or attribute evolved.

## Knowledge maturity

Profile data should retain maturity where useful:

~~~text
raw
observed
classified
knowledge
confirmed
~~~

An AI inference should not be silently promoted to confirmed personal truth.

## Provenance

Durable statements should be able to reference source memory/object, date observed, originating conversation/document, confidence, confirmation state and superseding/correcting memory.

## Extensibility

A new concept should not require a database migration.

~~~json
{
  "extensions": {
    "travel_preferences": {},
    "learning_style": {},
    "future_domain": {}
  }
}
~~~

Older clients may still read the parts they understand.

## Related identity documents

~~~text
identity/profile.mcma
identity/preferences.mcma
identity/relationships.mcma
identity/important-dates.mcma
identity/professional.mcma
identity/communication.mcma
~~~

The profile can act as the principal map to these documents.

## Biometric authentication

MCMA identity and biometric authentication are separate concerns.

Raw biometric data should remain under platform security mechanisms whenever possible.

Biometrics may unlock a cryptographic key; they should not become ordinary AI-readable profile content.
