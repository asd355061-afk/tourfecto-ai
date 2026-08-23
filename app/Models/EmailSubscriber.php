<?php

/**
 * Tourfecto - Email Subscriber Model (مشتركو تسويق البريد)
 * @version 1.0.0
 */
class EmailSubscriber extends Model
{
    protected $table = 'email_subscribers';
    protected $fillable = [
        'user_id', 'email', 'name', 'attributes', 'status',
        'unsubscribe_token', 'source', 'unsubscribed_at'
    ];
}
