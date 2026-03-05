# Экспресс чекаут Яндекс KIT

Модуль внешнего чекаута Яндекс KIT.

## Установка

1. Скопируйте модуль в директорию `/bitrix/modules/yastore.checkout/`
2. Перейдите в админ-панель Bitrix: **Настройки → Настройки продукта → Модули**
3. Найдите модуль **YaStore Checkout** и нажмите **Установить**
4. После установки перейдите в настройки модуля: **Настройки → Настройки модулей → YaStore Checkout**

## Настройка

### Токен

В настройках модуля укажите **Токен API**, который будет использоваться для авторизации запросов к API.

### Статусы

Для корректной работы модуля, необходимо проставить соответствие статусов.

## API

API доступно по адресу: `https://ваш-домен.ru/yastore.checkout/?method={method}`


Для просмотра документации:
1. Откройте файл `api-docs/openapi.yaml` в [Swagger Editor](https://editor.swagger.io/)
2. Или используйте локальный Swagger UI (см. инструкцию в `api-docs/README.md`)

### Основные методы API

#### 1. Получение складов
- **Метод:** `GET /?method=warehouses`
- **Описание:** Получить список активных складов

#### 2. Проверка корзины
- **Метод:** `POST /?method=checkBasket`
- **Описание:** Проверить наличие товаров и получить актуальные цены

#### 3. Создание заказа
- **Метод:** `POST /?method=orders`
- **Описание:** Создать новый заказ в системе

#### 4. Управление заказом
- **Метод:** `POST /?method=orders&action={action}&orderId={orderId}`
- **Действия:**
  - `placed` - отметить заказ как оплаченный
  - `cancel` - отменить заказ
  - `delivered` - отметить заказ как доставленный

#### 5. Настройки системы
- **Метод:** `GET /?method=settings`
- **Описание:** Получить настройки системы

### Авторизация

Все запросы требуют Bearer токен в заголовке:

```
Authorization: Bearer YOUR_TOKEN
```

Токен настраивается в админ-панели: **Настройки → Настройки модулей → YaStore Checkout → Токен API**

### Примеры использования

#### Получение складов

```bash
curl -X GET \
  'https://ваш-домен.ru/yastore.checkout/?method=warehouses' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

#### Проверка корзины

```bash
curl -X POST \
  'https://ваш-домен.ru/yastore.checkout/?method=checkBasket' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "items": [
      {
        "id": 50,
        "quantity": 2
      }
    ],
    "warehouse_id": "1"
  }'
```

#### Создание заказа

```bash
curl -X POST \
  'https://ваш-домен.ru/yastore.checkout/?method=orders' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "order_id": "ORDER_12345",
    "warehouse_id": "1",
    "items": [
      {
        "id": 50,
        "quantity": 2,
        "price": 1999.00,
        "final_price": 1999.00
      }
    ],
    "customer": {
      "full_name": "Иван Иванов",
      "phone": "+79991234567",
      "email": "ivan@example.com"
    },
    "delivery": {
      "type": "courier",
      "service": "YANDEX_GO",
      "address": {
        "address": "г. Москва, ул. Тестовая, д. 1, кв. 10",
        "city": "Москва",
        "street": "ул. Тестовая",
        "building": "1",
        "apartment": "10"
      }
    }
  }'
```

**Заметки:**
- Если в системе нет активных складов, возвращается виртуальный склад
- При создании заказа автоматически создается или обновляется профиль покупателя
- Все цены указываются в рублях

