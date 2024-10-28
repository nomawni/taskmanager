<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use OpenApi\Annotations as OA;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Service\Attribute\Required;

#[Route('/api/tasks')]
class TaskController extends AbstractController
{
    private RateLimiterFactory $rateLimiterFactory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskRepository $taskRepository,
        private ValidatorInterface $validator,
        private Security $security,
        private NotificationService $notificationService,
        RateLimiterFactory $rateLimiterFactory
    ) {
        $this->rateLimiterFactory = $rateLimiterFactory;
    }

    /**
     * Create a new task
     *
     * @OA\Post(
     *     path="/api/tasks",
     *     summary="Create a new task",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="status", type="string", enum={"pending", "in-progress", "completed"}),
     *             @OA\Property(property="due_date", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Task created successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     )
     * )
     */
    #[Route('', name: 'create_task', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // Rate limiting
        /** @var \App\Entity\User $user */
        $user = $this->security->getUser();
        $limiter = $this->rateLimiterFactory->create($user->getEmail());
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'Too many requests'], 429);
        }

        $data = json_decode($request->getContent(), true);

        $task = new Task();
        $task->setTitle($data['title'] ?? '');
        $task->setDescription($data['description'] ?? '');
        $task->setStatus($data['status'] ?? 'pending');
        if (isset($data['due_date'])) {
            $task->setDueDate(new \DateTime($data['due_date']));
        }
        $task->setUser($user);

        $errors = $this->validator->validate($task);
        if (count($errors) > 0) {
            $errorsString = (string) $errors;

            return new JsonResponse(['error' => $errorsString], 400);
        }

        $this->taskRepository->add($task);

        // Send notification
        $this->notificationService->sendTaskNotification($user, $task, 'create');

        return new JsonResponse(['message' => 'Task created successfully', 'task' => $this->serializeTask($task)], 201);
    }

    /**
     * Retrieve a list of tasks
     *
     * @OA\Get(
     *     path="/api/tasks",
     *     summary="Retrieve a list of tasks",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of tasks per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "in-progress", "completed"})
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search keyword",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of tasks"
     *     )
     * )
     */
    #[Route('', name: 'get_tasks', methods: ['GET'])]
    public function getTasks(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $status = $request->query->get('status');
        $search = $request->query->get('search');

        if ($search) {
            $tasks = $this->taskRepository->createQueryBuilder('t')
                ->where('t.user = :user')
                ->andWhere('t.title LIKE :search OR t.description LIKE :search')
                ->setParameter('user', $user)
                ->setParameter('search', '%'.$search.'%')
                ->orderBy('t.createdAt', 'DESC')
                ->setFirstResult(($page -1) * $limit)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        } else {
            $tasks = $this->taskRepository->findBy(['user' => $user], ['createdAt' => 'DESC'], $limit, ($page - 1) * $limit);
        }

        return new JsonResponse([
            'page' => $page,
            'limit' => $limit,
            'tasks' => array_map([$this, 'serializeTask'], $tasks)
        ], 200);
    }

    /**
     * Retrieve a specific task by ID
     *
     * @OA\Get(
     *     path="/api/tasks/{id}",
     *     summary="Retrieve a specific task by ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task details"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found"
     *     )
     * )
     */
    #[Route('/{id}', name: 'get_task', methods: ['GET'])]
    public function getTask(int $id): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        $user = $this->security->getUser();

        if (!$task || $task->getUser() !== $user) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        return new JsonResponse(['task' => $this->serializeTask($task)], 200);
    }

    /**
     * Update a specific task
     *
     * @OA\Put(
     *     path="/api/tasks/{id}",
     *     summary="Update a specific task",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="status", type="string", enum={"pending", "in-progress", "completed"}),
     *             @OA\Property(property="due_date", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task updated successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found"
     *     )
     * )
     */
    #[Route('/{id}', name: 'update_task', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        $user = $this->security->getUser();

        if (!$task || $task->getUser() !== $user) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) {
            $task->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $task->setDescription($data['description']);
        }
        if (isset($data['status'])) {
            $task->setStatus($data['status']);
        }
        if (isset($data['due_date'])) {
            $task->setDueDate(new \DateTime($data['due_date']));
        }

        $task->setUpdatedAt(new \DateTimeImmutable());

        $errors = $this->validator->validate($task);
        if (count($errors) > 0) {
            $errorsString = (string) $errors;

            return new JsonResponse(['error' => $errorsString], 400);
        }

        $this->taskRepository->add($task);

        // Send notification
        $this->notificationService->sendTaskNotification($user, $task, 'update');

        return new JsonResponse(['message' => 'Task updated successfully', 'task' => $this->serializeTask($task)], 200);
    }

    /**
     * Delete a specific task
     *
     * @OA\Delete(
     *     path="/api/tasks/{id}",
     *     summary="Delete a specific task",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task deleted successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found"
     *     )
     * )
     */
    #[Route('/{id}', name: 'delete_task', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        $user = $this->security->getUser();

        if (!$task || $task->getUser() !== $user) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        $this->taskRepository->remove($task);

        // Send notification
        $this->notificationService->sendTaskNotification($user, $task, 'delete');

        return new JsonResponse(['message' => 'Task deleted successfully'], 200);
    }

    private function serializeTask(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => $task->getStatus(),
            'due_date' => $task->getDueDate() ? $task->getDueDate()->format('Y-m-d H:i:s') : null,
            'created_at' => $task->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $task->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
