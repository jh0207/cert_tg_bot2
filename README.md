# Telegram SSL 证书机器人

## 异步任务（Cron）

通过 Cron 定时执行证书处理任务，避免阻塞 Webhook。

```
* * * * * php /www/wwwroot/tg-cert-bot/think cert:process
```
