# Сидеры (database/seeders)

Краткая шпаргалка: что есть и когда запускать.

## Автоматически при установке

```bash
php artisan db:seed --force
# или scripts/install.sh WITH_SEED=1
```

| Сидер | Что делает | Нужно в .env |
|-------|------------|--------------|
| **RoleSeeder** | Роли и права Spatie (Super Admin, Заместитель, …) | — |
| **SuperAdminSeeder** | Пользователь Super Admin | `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD` |
| **PlanSeeder** | Тарифы Free/Basic/Pro/Enterprise, банковский счёт, подписки | — |
| **LegalDocumentSeeder** | Оферта и политика конфиденциальности (если ещё нет) | — |
| **BotTrainingSeeder** | Правила габаритов для Product Bot (бренды + общие типы упаковки) | — |

**Не создаёт:** магазины, отделы, карту зала, демо-оборудование — это делается в UI.

---

## Только правила бота (повторно)

После правок `BotTrainingSeeder` или на уже живой БД:

```bash
php artisan db:seed --class=BotTrainingSeeder --force
```

Идемпотентно (`updateOrCreate` по keyword + package + volume range).

Что внутри:

1. **Брендовые / категорийные** правила (пиво, кола, pepsi, вода, сок, молоко, …) — высокий приоритет.
2. **Общие** правила без keyword (Банка/ПЭТ/Бутылка/Коробка/Пакет × объём) — срабатывают, когда OFF уже дал тип упаковки и объём, но бренд в названии не распознан.

Габариты в мм нужны планограмме; Open Food Facts почти никогда не отдаёт точный диаметр.

---

## Порядок для чистого VPS

```bash
CONFIRM=1 sudo ./scripts/reset.sh
# → migrate:fresh --seed (роли, админ, тарифы, legal, bot training)

# В UI: арендатор → магазин → товары
# Product Bot: /product-bot → Scan → принять предложения
```

---

## Проверка синтаксиса

```bash
for f in database/seeders/*.php; do php -l "$f"; done
```
