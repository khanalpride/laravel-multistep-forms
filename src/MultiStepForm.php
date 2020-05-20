<?php declare(strict_types=1);

namespace BayAreaWebPro\MultiStepForms;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Store;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Session\Store as Session;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;

class MultiStepForm implements Responsable, Arrayable
{
    public string $namespace = 'multistep-form';
    public Collection $after;
    public Collection $before;
    public Collection $steps;
    public Request $request;
    public Store $session;
    public array $data;
    public $view;

    /**
     * MultiStepForm constructor.
     * @param Request $request
     * @param Store $session
     * @param array $data
     * @param null $view
     */
    public function __construct(Request $request, Session $session, $data = [], $view = null)
    {
        $this->after = new Collection;
        $this->before = new Collection;
        $this->steps = new Collection;
        $this->request = $request;
        $this->session = $session;
        $this->view = $view;
        $this->data = $data;
    }

    /**
     * Make MultiStepForm Instance
     * @param null $view
     * @param array $data
     * @return static
     */
    public static function make($view = null, array $data = []): self
    {
        return app(static::class, [
            'view' => $view,
            'data' => $data,
        ]);
    }

    /**
     * Set the session namespace.
     * @param string $namespace
     * @return $this
     */
    public function namespaced(string $namespace): self
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Tap into instance (invokable Classes).
     * @param Closure|mixed $closure
     * @return $this
     */
    public function tap($closure)
    {
        $closure($this);
        return $this;
    }

    /**
     * Add Before Step callback
     * @param int|string $step
     * @param Closure $closure
     * @return $this
     */
    public function beforeStep($step, Closure $closure): self
    {
        $this->before->put($step, $closure);
        return $this;
    }

    /**
     * Add Step callback
     * @param int|string $step
     * @param Closure $closure
     * @return $this
     */
    public function onStep($step, Closure $closure): self
    {
        $this->after->put($step, $closure);
        return $this;
    }

    /**
     * Add step configuration.
     * @param int $step
     * @param array $config
     * @return $this
     */
    public function addStep(int $step, array $config = []): self
    {
        $this->steps->put($step, $config);
        return $this;
    }

    /**
     * Get Current Step
     * @return int
     */
    public function currentStep(): int
    {
        return (int)$this->request->get('form_step',
            $this->session->get("{$this->namespace}.form_step", 1)
        );
    }

    /**
     * Get the current step config or by number.
     * @param int $step
     * @return Collection
     */
    public function stepConfig(?int $step = null): Collection
    {
        return Collection::make($this->steps->get($step ?? $this->currentStep()));
    }

    /**
     * Determine the current step.
     * @param int $step
     * @return bool
     */
    public function isStep(int $step = 1): bool
    {
        return $this->currentStep() === $step;
    }

    /**
     * Get v value.
     * @param string $key
     * @param null $fallback
     * @return mixed
     */
    public function getValue(string $key, $fallback = null)
    {
        return $this->session->get("{$this->namespace}.$key", $fallback);
    }

    /**
     * Set session value.
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setValue(string $key, $value): self
    {
        $this->session->put("{$this->namespace}.$key", $value);
        return $this;
    }

    /**
     * Increment the current step to the next.
     * @return $this
     */
    protected function nextStep(): self
    {
        if ($this->isStep(0)) {
            $this->session->put("{$this->namespace}.form_step", 1);
            $this->session->save();
        } else if (!$this->isStep($this->lastStep())) {
            $this->session->increment("{$this->namespace}.form_step");
            $this->session->save();
        }
        return $this;
    }

    /**
     * Save the validation data to the session.
     * @param array $data
     * @return $this
     */
    protected function save(array $data): self
    {
        $this->session->put($this->namespace, array_merge(
            $this->session->get($this->namespace, []), $data,
            ['form_step' => $this->currentStep()]
        ));
        $this->session->save();
        return $this;
    }

    /**
     * Reset session state.
     * @param array $data
     * @return $this
     */
    public function reset($data = []): self
    {
        $this->request->merge(['form_step' => 0]);
        $this->session->put($this->namespace, $data);
        $this->session->save();
        return $this;
    }

    /**
     * Handle "Before" Callback
     * @param int|string $key
     * @return mixed
     */
    protected function handleBefore($key)
    {
        if ($callback = $this->before->get($key)) {
            return $callback($this);
        }
    }

    /**
     * Handle "After" Callback
     * @param int|string $key
     * @return mixed
     */
    protected function handleAfter($key)
    {
        if ($callback = $this->after->get($key)) {
            return $callback($this);
        }
    }

    /**
     * @return array
     */
    protected function validate(): array
    {
        $step = $this->stepConfig($this->currentStep());

        return $this->request->validate(
            array_merge($step->get('rules', []), [
                'form_step' => ['required', 'numeric', Rule::in(range(1, $this->lastStep()))],
            ]),
            $step->get('messages', [])
        );
    }

    /**
     * Highest Step
     * @return int
     */
    public function lastStep(): int
    {
        return $this->steps->keys()->max(fn($value) => $value) ?? 1;
    }

    /**
     * Create an HTTP response that represents the object.
     * @param \Illuminate\Http\Request|null $request
     * @return \Illuminate\Http\Response
     */
    public function toResponse($request = null)
    {
        $this->request = $request ?? $this->request;
        if ($this->request->isMethod('GET')) {
            return $this->renderResponse();
        }
        return $this->handleRequest();
    }

    /**
     * Render the request as a response.
     * @return \Illuminate\Contracts\View\View|Response
     */
    protected function renderResponse()
    {
        if (is_string($this->view) && !$this->request->wantsJson()) {
            return View::make($this->view, array_merge($this->data, ['form' => $this]));
        }
        return new Response([
            'data' => array_merge($this->data, $this->stepConfig()->get('data', [])),
            'form' => $this->toArray(),
        ]);
    }

    /**
     * Handle the validated request.
     * @return mixed
     */
    protected function handleRequest()
    {
        if ($response = (
            $this->handleBefore('*') ??
            $this->handleBefore($this->currentStep())
        )) {
            return $response;
        }

        $this->save($this->validate());

        if ($response = (
            $this->handleAfter('*') ??
            $this->handleAfter($this->currentStep())
        )) {
            return $response;
        }

        $this->nextStep();

        if (!$this->request->wantsJson()) {
            return redirect()->back();
        }
        return $this->renderResponse();
    }

    /**
     * Get the instance as an array.
     * @return array
     */
    public function toArray(): array
    {
        return $this->session->get($this->namespace, []);
    }

    /**
     * Get the instance as an Collection.
     * @return Collection
     */
    public function toCollection(): Collection
    {
        return Collection::make($this->toArray());
    }
}
