<?php

namespace App\Services\Chat;

/**
 * Telegram Channel Integration Service
 * 
 * Provides integration with Telegram Bot API for:
 * - Receiving and sending messages
 * - Message synchronization with unified inbox
 * - Auto-reply support
 * - Rich media handling
 * 
 * @package App\Services\Chat
 */
class TelegramChannelService
{
    private string $apiBaseUrl = 'https://api.telegram.org/bot';
    private ?string $botToken = null;
    
    /**
     * Constructor
     * 
     * @param string|null $botToken Telegram Bot Token
     */
    public function __construct(?string $botToken = null)
    {
        $this->botToken = $botToken ?? getenv('TELEGRAM_BOT_TOKEN');
    }
    
    /**
     * Set bot token
     */
    public function setBotToken(string $token): self
    {
        $this->botToken = $token;
        return $this;
    }
    
    /**
     * Get bot token
     */
    public function getBotToken(): ?string
    {
        return $this->botToken;
    }
    
    /**
     * Verify webhook signature from Telegram
     * 
     * @param array $update Update data from Telegram
     * @return bool True if valid
     */
    public function verifyWebhook(array $update): bool
    {
        // Telegram doesn't sign webhooks like Facebook
        // Just validate the structure
        return isset($update['update_id']) && is_array($update);
    }
    
    /**
     * Process incoming message from Telegram
     * 
     * @param array $update Update data from Telegram
     * @param int $tenantId Tenant identifier
     * @return array Processed message data
     */
    public function processIncomingMessage(array $update, int $tenantId): array
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        
        if (!$message) {
            return ['success' => false, 'error' => 'No message found in update'];
        }
        
        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        
        // Extract message content
        $messageContent = $this->extractMessageContent($message);
        
        // Build normalized message structure
        $normalizedMessage = [
            'channel' => 'telegram',
            'channel_message_id' => (string) $message['message_id'],
            'channel_chat_id' => (string) $chat['id'],
            'tenant_id' => $tenantId,
            'from' => [
                'id' => (string) ($from['id'] ?? ''),
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
                'is_bot' => $from['is_bot'] ?? false
            ],
            'chat' => [
                'id' => (string) $chat['id'],
                'type' => $chat['type'] ?? 'private',
                'title' => $chat['title'] ?? null,
                'username' => $chat['username'] ?? null
            ],
            'message' => $messageContent,
            'timestamp' => date('Y-m-d H:i:s', $message['date']),
            'is_reply' => isset($message['reply_to_message']),
            'reply_to_message_id' => $message['reply_to_message']['message_id'] ?? null,
            'raw_update' => $update
        ];
        
        // Handle special message types
        if (isset($message['new_chat_members'])) {
            $normalizedMessage['event_type'] = 'new_member';
            $normalizedMessage['new_members'] = $message['new_chat_members'];
        }
        
        if (isset($message['left_chat_member'])) {
            $normalizedMessage['event_type'] = 'member_left';
            $normalizedMessage['left_member'] = $message['left_chat_member'];
        }
        
        return [
            'success' => true,
            'message' => $normalizedMessage
        ];
    }
    
    /**
     * Send text message via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $text Message text
     * @param array $options Additional options (parse_mode, reply_markup, etc.)
     * @return array API response
     */
    public function sendTextMessage(string $chatId, string $text, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text
        ];
        
        // Add optional parameters
        if (!empty($options['parse_mode'])) {
            $params['parse_mode'] = $options['parse_mode']; // Markdown, HTML, MarkdownV2
        }
        
        if (!empty($options['reply_markup'])) {
            $params['reply_markup'] = json_encode($options['reply_markup']);
        }
        
        if (!empty($options['reply_to_message_id'])) {
            $params['reply_to_message_id'] = $options['reply_to_message_id'];
        }
        
        if (!empty($options['disable_notification'])) {
            $params['disable_notification'] = true;
        }
        
        return $this->callApi('sendMessage', $params);
    }
    
    /**
     * Send photo via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $photo Photo URL or file ID
     * @param string|null $caption Photo caption
     * @param array $options Additional options
     * @return array API response
     */
    public function sendPhoto(string $chatId, string $photo, ?string $caption = null, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo
        ];
        
        if ($caption !== null) {
            $params['caption'] = $caption;
        }
        
        if (!empty($options['parse_mode'])) {
            $params['parse_mode'] = $options['parse_mode'];
        }
        
        if (!empty($options['reply_markup'])) {
            $params['reply_markup'] = json_encode($options['reply_markup']);
        }
        
        return $this->callApi('sendPhoto', $params);
    }
    
    /**
     * Send document via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $document Document URL or file ID
     * @param string|null $caption Document caption
     * @param array $options Additional options
     * @return array API response
     */
    public function sendDocument(string $chatId, string $document, ?string $caption = null, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'document' => $document
        ];
        
        if ($caption !== null) {
            $params['caption'] = $caption;
        }
        
        if (!empty($options['parse_mode'])) {
            $params['parse_mode'] = $options['parse_mode'];
        }
        
        return $this->callApi('sendDocument', $params);
    }
    
    /**
     * Send video via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $video Video URL or file ID
     * @param string|null $caption Video caption
     * @param array $options Additional options
     * @return array API response
     */
    public function sendVideo(string $chatId, string $video, ?string $caption = null, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'video' => $video
        ];
        
        if ($caption !== null) {
            $params['caption'] = $caption;
        }
        
        if (!empty($options['parse_mode'])) {
            $params['parse_mode'] = $options['parse_mode'];
        }
        
        return $this->callApi('sendVideo', $params);
    }
    
    /**
     * Send audio via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $audio Audio URL or file ID
     * @param string|null $caption Audio caption
     * @param array $options Additional options
     * @return array API response
     */
    public function sendAudio(string $chatId, string $audio, ?string $caption = null, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'audio' => $audio
        ];
        
        if ($caption !== null) {
            $params['caption'] = $caption;
        }
        
        return $this->callApi('sendAudio', $params);
    }
    
    /**
     * Send voice message via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $voice Voice note URL or file ID
     * @param string|null $caption Voice caption
     * @param array $options Additional options
     * @return array API response
     */
    public function sendVoice(string $chatId, string $voice, ?string $caption = null, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'voice' => $voice
        ];
        
        if ($caption !== null) {
            $params['caption'] = $caption;
        }
        
        return $this->callApi('sendVoice', $params);
    }
    
    /**
     * Send location via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @param array $options Additional options
     * @return array API response
     */
    public function sendLocation(string $chatId, float $latitude, float $longitude, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
        
        if (!empty($options['live_period'])) {
            $params['live_period'] = $options['live_period'];
        }
        
        return $this->callApi('sendLocation', $params);
    }
    
    /**
     * Send contact via Telegram
     * 
     * @param string $chatId Telegram chat ID
     * @param string $phoneNumber Contact phone number
     * @param string $firstName Contact first name
     * @param array $options Additional options
     * @return array API response
     */
    public function sendContact(string $chatId, string $phoneNumber, string $firstName, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'phone_number' => $phoneNumber,
            'first_name' => $firstName
        ];
        
        if (!empty($options['last_name'])) {
            $params['last_name'] = $options['last_name'];
        }
        
        return $this->callApi('sendContact', $params);
    }
    
    /**
     * Edit message text
     * 
     * @param string $chatId Telegram chat ID
     * @param int $messageId Message ID to edit
     * @param string $text New text
     * @param array $options Additional options
     * @return array API response
     */
    public function editMessageText(string $chatId, int $messageId, string $text, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text
        ];
        
        if (!empty($options['parse_mode'])) {
            $params['parse_mode'] = $options['parse_mode'];
        }
        
        if (!empty($options['reply_markup'])) {
            $params['reply_markup'] = json_encode($options['reply_markup']);
        }
        
        return $this->callApi('editMessageText', $params);
    }
    
    /**
     * Delete message
     * 
     * @param string $chatId Telegram chat ID
     * @param int $messageId Message ID to delete
     * @return array API response
     */
    public function deleteMessage(string $chatId, int $messageId): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ];
        
        return $this->callApi('deleteMessage', $params);
    }
    
    /**
     * Send chat action (typing, uploading photo, etc.)
     * 
     * @param string $chatId Telegram chat ID
     * @param string $action Chat action type
     * @return array API response
     */
    public function sendChatAction(string $chatId, string $action): array
    {
        $allowedActions = [
            'typing',
            'upload_photo',
            'record_video',
            'upload_video',
            'record_audio',
            'upload_audio',
            'upload_document',
            'find_location',
            'record_video_note',
            'upload_video_note'
        ];
        
        if (!in_array($action, $allowedActions)) {
            return ['success' => false, 'error' => 'Invalid chat action'];
        }
        
        $params = [
            'chat_id' => $chatId,
            'action' => $action
        ];
        
        return $this->callApi('sendChatAction', $params);
    }
    
    /**
     * Get chat information
     * 
     * @param string $chatId Telegram chat ID
     * @return array Chat information
     */
    public function getChat(string $chatId): array
    {
        $params = ['chat_id' => $chatId];
        return $this->callApi('getChat', $params);
    }
    
    /**
     * Get chat administrators
     * 
     * @param string $chatId Telegram chat ID
     * @return array List of administrators
     */
    public function getChatAdministrators(string $chatId): array
    {
        $params = ['chat_id' => $chatId];
        return $this->callApi('getChatAdministrators', $params);
    }
    
    /**
     * Get chat member count
     * 
     * @param string $chatId Telegram chat ID
     * @return array Member count
     */
    public function getChatMemberCount(string $chatId): array
    {
        $params = ['chat_id' => $chatId];
        return $this->callApi('getChatMemberCount', $params);
    }
    
    /**
     * Set webhook for receiving updates
     * 
     * @param string $url Webhook URL
     * @param array $options Additional options
     * @return array API response
     */
    public function setWebhook(string $url, array $options = []): array
    {
        $params = ['url' => $url];
        
        if (!empty($options['certificate'])) {
            $params['certificate'] = $options['certificate'];
        }
        
        if (isset($options['max_connections'])) {
            $params['max_connections'] = $options['max_connections'];
        }
        
        if (!empty($options['allowed_updates'])) {
            $params['allowed_updates'] = json_encode($options['allowed_updates']);
        }
        
        return $this->callApi('setWebhook', $params);
    }
    
    /**
     * Delete webhook
     * 
     * @return array API response
     */
    public function deleteWebhook(): array
    {
        return $this->callApi('deleteWebhook', []);
    }
    
    /**
     * Get webhook info
     * 
     * @return array Webhook information
     */
    public function getWebhookInfo(): array
    {
        return $this->callApi('getWebhookInfo', []);
    }
    
    /**
     * Get updates (long polling alternative to webhook)
     * 
     * @param int|null $offset Offset for updates
     * @param int|null $limit Maximum number of updates
     * @param int|null $timeout Timeout in seconds
     * @param array|null $allowedUpdates List of update types to receive
     * @return array Updates
     */
    public function getUpdates(?int $offset = null, ?int $limit = null, ?int $timeout = null, ?array $allowedUpdates = null): array
    {
        $params = [];
        
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        
        if ($timeout !== null) {
            $params['timeout'] = $timeout;
        }
        
        if ($allowedUpdates !== null) {
            $params['allowed_updates'] = json_encode($allowedUpdates);
        }
        
        return $this->callApi('getUpdates', $params);
    }
    
    /**
     * Extract message content from Telegram message
     * 
     * @param array $message Telegram message object
     * @return array Normalized message content
     */
    private function extractMessageContent(array $message): array
    {
        $content = [
            'type' => 'text',
            'text' => null,
            'entities' => [],
            'media' => [],
            'location' => null,
            'contact' => null
        ];
        
        // Text message
        if (isset($message['text'])) {
            $content['type'] = 'text';
            $content['text'] = $message['text'];
            
            if (isset($message['entities'])) {
                $content['entities'] = $message['entities'];
            }
        }
        
        // Photo
        if (isset($message['photo'])) {
            $content['type'] = 'photo';
            $photos = $message['photo'];
            $content['media'] = [
                'file_id' => end($photos)['file_id'],
                'file_unique_id' => end($photos)['file_unique_id'],
                'width' => end($photos)['width'],
                'height' => end($photos)['height'],
                'file_size' => end($photos)['file_size']
            ];
            if (isset($message['caption'])) {
                $content['text'] = $message['caption'];
            }
        }
        
        // Document
        if (isset($message['document'])) {
            $content['type'] = 'document';
            $doc = $message['document'];
            $content['media'] = [
                'file_id' => $doc['file_id'],
                'file_unique_id' => $doc['file_unique_id'],
                'file_name' => $doc['file_name'] ?? null,
                'mime_type' => $doc['mime_type'] ?? null,
                'file_size' => $doc['file_size'] ?? null
            ];
            if (isset($message['caption'])) {
                $content['text'] = $message['caption'];
            }
        }
        
        // Video
        if (isset($message['video'])) {
            $content['type'] = 'video';
            $video = $message['video'];
            $content['media'] = [
                'file_id' => $video['file_id'],
                'file_unique_id' => $video['file_unique_id'],
                'width' => $video['width'],
                'height' => $video['height'],
                'duration' => $video['duration'],
                'file_size' => $video['file_size'] ?? null
            ];
            if (isset($message['caption'])) {
                $content['text'] = $message['caption'];
            }
        }
        
        // Audio
        if (isset($message['audio'])) {
            $content['type'] = 'audio';
            $audio = $message['audio'];
            $content['media'] = [
                'file_id' => $audio['file_id'],
                'file_unique_id' => $audio['file_unique_id'],
                'duration' => $audio['duration'],
                'file_size' => $audio['file_size'] ?? null
            ];
        }
        
        // Voice
        if (isset($message['voice'])) {
            $content['type'] = 'voice';
            $voice = $message['voice'];
            $content['media'] = [
                'file_id' => $voice['file_id'],
                'file_unique_id' => $voice['file_unique_id'],
                'duration' => $voice['duration'],
                'file_size' => $voice['file_size'] ?? null
            ];
        }
        
        // Location
        if (isset($message['location'])) {
            $content['type'] = 'location';
            $content['location'] = $message['location'];
        }
        
        // Contact
        if (isset($message['contact'])) {
            $content['type'] = 'contact';
            $content['contact'] = $message['contact'];
        }
        
        // Sticker
        if (isset($message['sticker'])) {
            $content['type'] = 'sticker';
            $sticker = $message['sticker'];
            $content['media'] = [
                'file_id' => $sticker['file_id'],
                'file_unique_id' => $sticker['file_unique_id'],
                'emoji' => $sticker['emoji'] ?? null,
                'set_name' => $sticker['set_name'] ?? null
            ];
        }
        
        return $content;
    }
    
    /**
     * Call Telegram Bot API
     * 
     * @param string $method API method name
     * @param array $params Method parameters
     * @return array API response
     */
    private function callApi(string $method, array $params): array
    {
        if (!$this->botToken) {
            return [
                'success' => false,
                'error' => 'Bot token not configured'
            ];
        }
        
        $url = "{$this->apiBaseUrl}{$this->botToken}/{$method}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => "cURL error: {$error}",
                'http_code' => $httpCode
            ];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from Telegram API',
                'http_code' => $httpCode
            ];
        }
        
        if (!($result['ok'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['description'] ?? 'Unknown error',
                'error_code' => $result['error_code'] ?? null,
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'result' => $result['result'] ?? null,
            'http_code' => $httpCode
        ];
    }
}
