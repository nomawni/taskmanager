<?php
// src/Service/NotificationService.php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Entity\User;
use App\Entity\Task;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private HttpClientInterface $client,
        private string $sendGridApiKey,
        private string $fromEmail,
        private LoggerInterface $logger
    ) {}

    public function sendTaskNotification(User $user, Task $task, string $action): bool
    {
        $subject = '';
        $message = '';

        switch ($action) {
            case 'create':
                $subject = 'New Task Created';
                $message = sprintf("Hello %s,\n\nA new task '%s' has been created.", $user->getEmail(), $task->getTitle());
                break;
            case 'update':
                $subject = 'Task Updated';
                $message = sprintf("Hello %s,\n\nThe task '%s' has been updated.", $user->getEmail(), $task->getTitle());
                break;
            case 'delete':
                $subject = 'Task Deleted';
                $message = sprintf("Hello %s,\n\nThe task '%s' has been deleted.", $user->getEmail(), $task->getTitle());
                break;
            default:
                $this->logger->warning('Unknown action for task notification', ['action' => $action]);
                return false;
        }

        $emailData = [
            'personalizations' => [
                [
                    'to' => [
                        ['email' => $user->getEmail()]
                    ],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $this->fromEmail
            ],
            'content' => [
                [
                    'type' => 'text/plain',
                    'value' => $message
                ]
            ]
        ];

        try {
            $response = $this->client->request('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->sendGridApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $emailData,
            ]);

            if ($response->getStatusCode() === 202) {
                $this->logger->info('Task notification sent successfully', ['action' => $action, 'user' => $user->getEmail(), 'task' => $task->getTitle()]);
                return true;
            } else {
                $this->logger->error('Failed to send task notification', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false)
                ]);
                return false;
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception while sending task notification', ['exception' => $e->getMessage()]);
            return false;
        }
    }
}
