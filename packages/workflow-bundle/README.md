# nubitio/workflow-bundle

> **Read-only split** of [`nubitio/nubit-symfony`](https://github.com/nubitio/nubit-symfony). Open issues and pull requests there. Commits here are overwritten on every split from `main`.

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

The route prefix is inferred from the resource's `GetCollection` URI template
(`/api` + `/orders`). Pass `routePrefix: '/api/orders'` to the attribute only
when you need to override that — e.g. a non-standard operation path.

The Hydra API doc publishes `x-workflow` so `@nubitio/react-admin` can render row actions automatically.