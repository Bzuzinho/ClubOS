# API Endpoints Documentation

## Base URL
All API endpoints are prefixed with `/api` and require authentication via Sanctum middleware.

## Authentication
All requests must include a valid Sanctum token in the `Authorization` header:
```
Authorization: Bearer {token}
```

---

## Key-Value Store

Generic key-value storage for flexible data persistence.

### Get Value
**GET** `/api/kv/{key}`

Query Parameters:
- `scope` (optional): `global` or `user` (default: `global`)

Response:
```json
{
  "key": "club-users",
  "value": [...],
  "scope": "global"
}
```

### Set Value
**PUT** `/api/kv/{key}`

Body:
```json
{
  "value": "any JSON serializable value",
  "scope": "global"
}
```

### Delete Value
**DELETE** `/api/kv/{key}`

Query Parameters:
- `scope` (optional): `global` or `user`

---

## Users

### List Users
**GET** `/api/users`

Response: Array of user objects

### Get User
**GET** `/api/users/{id}`

Response: Single user object with relationships

### Create User
**POST** `/api/users`

Body:
```json
{
  "nome_completo": "João Silva",
  "email": "joao@example.com",
  "data_nascimento": "2000-01-15",
  "tipo_membro": ["atleta"],
  "estado": "ativo",
  "perfil": "atleta",
  "sexo": "M"
}
```

### Update User
**PUT** `/api/users/{id}`

Body: Partial user object (only fields to update)

### Delete User
**DELETE** `/api/users/{id}`

---

## Events

### List Events
**GET** `/api/events`

Query Parameters:
- `type` (optional): Filter by event type
- `status` (optional): Filter by status
- `start_date` (optional): Filter by start date (>=)
- `end_date` (optional): Filter by end date (<=)

### Get Event
**GET** `/api/events/{id}`

### Create Event
**POST** `/api/events`

Body:
```json
{
  "title": "Treino Técnico",
  "description": "Sessão focada em técnica de nado",
  "start_date": "2026-02-10",
  "start_time": "18:00",
  "end_time": "20:00",
  "location": "Piscina Municipal",
  "type": "training",
  "status": "published"
}
```

### Update Event
**PUT** `/api/events/{id}`

Body: Partial event object

### Delete Event
**DELETE** `/api/events/{id}`

---

## Provas (Races)

### List Provas
**GET** `/api/provas`

### Get Prova
**GET** `/api/provas/{id}`

### Create Prova
**POST** `/api/provas`

Body:
```json
{
  "name": "100m Livres",
  "distance": 100,
  "stroke": "freestyle",
  "gender": "M",
  "age_group": "juvenis"
}
```

### Update Prova
**PUT** `/api/provas/{id}`

### Delete Prova
**DELETE** `/api/provas/{id}`

---

## Results

### List Results
**GET** `/api/results`

Query Parameters:
- `athlete_id` (optional): Filter by athlete
- `event_id` (optional): Filter by event

### Get Result
**GET** `/api/results/{id}`

### Create Result
**POST** `/api/results`

Body:
```json
{
  "athlete_id": "uuid",
  "event_id": "uuid",
  "event_name": "Campeonato Nacional",
  "race": "100m Livres",
  "location": "Lisboa",
  "date": "2026-03-15",
  "pool": "piscina_25m",
  "final_time": "00:58:45"
}
```

### Update Result
**PUT** `/api/results/{id}`

### Delete Result
**DELETE** `/api/results/{id}`

---

## Event Attendances

### List Attendances
**GET** `/api/event-attendances`

Query Parameters:
- `event_id` (optional): Filter by event
- `user_id` (optional): Filter by user

### Get Attendance
**GET** `/api/event-attendances/{id}`

### Create Attendance
**POST** `/api/event-attendances`

Body:
```json
{
  "event_id": "uuid",
  "user_id": "uuid",
  "status": "present",
  "arrival_time": "2026-02-10 18:05:00",
  "notes": "Chegou atrasado mas treinou bem"
}
```

Status values: `present`, `absent`, `excused`, `late`

### Update Attendance
**PUT** `/api/event-attendances/{id}`

### Delete Attendance
**DELETE** `/api/event-attendances/{id}`

---

## Settings APIs

### User Types
- **GET** `/api/user-types` - List all user types
- **POST** `/api/user-types` - Create user type
- **GET** `/api/user-types/{id}` - Get user type
- **PUT** `/api/user-types/{id}` - Update user type
- **DELETE** `/api/user-types/{id}` - Delete user type

### Age Groups
- **GET** `/api/age-groups` - List all age groups
- Full CRUD operations available

### Event Types
- **GET** `/api/event-types` - List all event types
- Full CRUD operations available

### Cost Centers
- **GET** `/api/cost-centers` - List all cost centers
- Full CRUD operations available

### Club Settings
- **GET** `/api/club-settings` - List all settings
- Full CRUD operations available

---

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 404 Not Found
```json
{
  "message": "Resource not found."
}
```

### 422 Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

---

## React Query Hooks

All endpoints have corresponding React hooks for easy integration:

### Key-Value
- `useKV<T>(key, defaultValue, options)`

### Users
- `useUsers()`, `useUser(id)`, `useCreateUser()`, `useUpdateUser()`, `useDeleteUser()`

### Events
- `useEvents(filters?)`, `useEvent(id)`, `useCreateEvent()`, `useUpdateEvent()`, `useDeleteEvent()`

### Provas
- `useProvas()`, `useProva(id)`, `useCreateProva()`, `useUpdateProva()`, `useDeleteProva()`

### Results
- `useResults(filters?)`, `useResult(id)`, `useCreateResult()`, `useUpdateResult()`, `useDeleteResult()`

### Attendances
- `useEventAttendances(filters?)`, `useEventAttendance(id)`, `useCreateEventAttendance()`, `useUpdateEventAttendance()`, `useDeleteEventAttendance()`

### Current User
- `useCurrentUser()`, `useIsAdmin()`, `useIsAuthenticated()`, `useHasRole(role)`, `useUserId()`

---

## Example Usage

```typescript
import { useEvents, useCreateEvent } from '@/hooks';

function EventsPage() {
  const { data: events = [], isLoading } = useEvents({ type: 'training' });
  const createEvent = useCreateEvent();

  const handleCreate = async () => {
    await createEvent.mutateAsync({
      title: 'Novo Treino',
      start_date: '2026-02-10',
      type: 'training',
    });
  };

  if (isLoading) return <div>Loading...</div>;

  return (
    <div>
      {events.map(event => (
        <div key={event.id}>{event.title}</div>
      ))}
      <button onClick={handleCreate}>Create Event</button>
    </div>
  );
}
```
