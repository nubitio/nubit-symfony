# nubitio/workflow-bundle

Opt-in state-machine kit for Nubit Symfony apps.

## Install

```bash
composer require nubitio/workflow-bundle
```

Register routes (e.g. `config/routes/nubit_workflow.yaml`):

```yaml
nubit_workflow:
    resource: '@NubitWorkflowBundle/config/workflow_routes.yaml'
```

Enable in `config/packages/nubit_workflow.yaml`:

```yaml
nubit_workflow:
    enabled: true
```

## Usage

```php
use Nubit\WorkflowBundle\Attribute\Workflow;

#[Workflow(
    field: 'status',
    transitions: [
        'send_to_kitchen' => [
            'from' => ['open'],
            'to' => 'preparing',
            'label' => 'Enviar a cocina',
            'roles' => ['ROLE_WAITER'],
        ],
        'pay' => [
            'from' => ['served', 'open'],
            'to' => 'paid',
            'label' => 'Cobrar',
            'set' => ['paymentMethod' => 'cash'],
        ],
    ],
)]
class Order { ... }
```

Transitions are exposed as:

```
POST /api/orders/{id}/transition/{name}
```

The Hydra API doc publishes `x-workflow` so `@nubitio/react-admin` can render row actions automatically.