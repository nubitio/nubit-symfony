# nubitio/sequence-bundle

Opt-in atomic numbering for document fields.

## Install

```bash
composer require nubitio/sequence-bundle
```

Create the counter table (once per app):

```sql
CREATE TABLE nubit_sequence_counter (
    id SERIAL PRIMARY KEY,
    scope_key VARCHAR(190) NOT NULL,
    name VARCHAR(64) NOT NULL,
    next_value INT NOT NULL DEFAULT 1,
    CONSTRAINT UNIQ_SEQUENCE_SCOPE_NAME UNIQUE (scope_key, name)
);
```

Or run a Doctrine migration that maps `Nubit\SequenceBundle\Entity\SequenceCounter`.

## Usage

```php
use Nubit\SequenceBundle\Attribute\Sequence;

#[Sequence(field: 'number', name: 'order', prefix: 'ORD-', padding: 4, scope: ['restaurant'])]
class Order
{
    // number is allocated on POST when left empty
}
```

Counters are isolated per scope (`restaurant:1|…`) and sequence name (`order`).

## Frontend integration

The Hydra API doc publishes `x-sequence` on the resource class so `@nubitio/react-admin` hides the allocated field on create/edit forms and marks it read-only in the grid:

```json
{
  "@id": "#Order",
  "x-sequence": {
    "field": "number",
    "name": "order",
    "prefix": "ORD-",
    "padding": 4,
    "scope": ["restaurant"]
  }
}
```

No manual `visibleOnForm: false` on the sequence property is required when the bundle is enabled.