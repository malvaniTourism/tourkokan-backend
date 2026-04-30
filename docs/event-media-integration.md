# Event Media Integration Guide

> Covers all changes required in the **Admin Panel** and **User App** for banner image upload, gallery management, and video URL.

---

## What the Backend Now Provides

### Event object (list & detail)
Every event response now includes a computed `banner_image_url` field — a ready-to-use S3 URL. Use this directly in `<img>` tags; do **not** construct the URL manually.

```json
{
  "id": 12,
  "title": "Devgad Mango Festival",
  "banner_image": "dev/events/banners/abc123.jpg",   // raw path — ignore this
  "banner_image_url": "https://tourkokan-....s3.eu-north-1.amazonaws.com/dev/events/banners/abc123.jpg",  // USE THIS
  "video_url": "https://youtube.com/watch?v=xxx",
  "status": "completed",
  ...
}
```

### Gallery item object (`getEventGallery` response)
```json
{
  "id": 5,
  "title": "Mango Festival Day 1",
  "description": "Opening ceremony",
  "path": "dev/events/gallery/img456.jpg",     // raw path — ignore this
  "path_url": "https://tourkokan-....s3.eu-north-1.amazonaws.com/dev/events/gallery/img456.jpg",  // USE THIS
  "is_url": false,
  "status": true
}
```

---

## API Reference

### All requests use `multipart/form-data` when uploading files

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `POST /api/v2/listEvents` | POST | Required | List events (user) |
| `GET /api/v2/events/{slug}` | GET | Required | Get single event (user) |
| `POST /api/v2/createEvent` | POST | Required | Create event with banner |
| `POST /api/v2/updateEvent` | POST | Required | Update event with banner |
| `POST /api/v2/getEventGallery` | POST | Required | Get event gallery (user) |
| `POST /api/v2/uploadEventGallery` | POST | Required | Upload gallery (completed only) |
| `POST /api/v2/deleteEventGallery` | POST | Required | Delete a gallery image |
| `POST /admin/v2/listEvents` | POST | Admin | List events (admin) |
| `POST /admin/v2/getEvent` | POST | Admin | Get single event (admin) |
| `POST /admin/v2/createEvent` | POST | Admin | Create event with banner |
| `POST /admin/v2/updateEvent` | POST | Admin | Update event with banner |
| `POST /admin/v2/getEventGallery` | POST | Admin | Get event gallery (admin) |
| `POST /admin/v2/uploadEventGallery` | POST | Admin | Upload gallery (completed only) |
| `POST /admin/v2/deleteEventGallery` | POST | Admin | Delete a gallery image |

---

## 1. Admin Panel Changes

### 1.1 Event List Page

**Currently:** Table rows show event title, status, dates etc.

**Add:** Display `banner_image_url` as a thumbnail in the list.

```jsx
// React example
<img
  src={event.banner_image_url || '/placeholder-event.png'}
  alt={event.title}
  style={{ width: 60, height: 40, objectFit: 'cover', borderRadius: 4 }}
/>
```

---

### 1.2 Event Detail / Get Page

**Currently:** Shows all event fields as text.

**Add:**

**Banner section:**
```jsx
{event.banner_image_url && (
  <img
    src={event.banner_image_url}
    alt={event.title}
    style={{ width: '100%', maxHeight: 400, objectFit: 'cover' }}
  />
)}
```

**Video URL section:**
```jsx
{event.video_url && (
  <a href={event.video_url} target="_blank" rel="noreferrer">
    Watch Video
  </a>
  // OR embed an iframe for YouTube/Vimeo
)}
```

**Gallery section** (only shown when `event.status === 'completed'`):
```jsx
// Fetch gallery on mount
// POST /admin/v2/getEventGallery  { "event_id": event.id }

{event.status === 'completed' && (
  <div>
    <h3>Gallery</h3>
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
      {galleries.map(g => (
        <div key={g.id} style={{ position: 'relative' }}>
          <img src={g.path_url} alt={g.title} style={{ width: 120, height: 80, objectFit: 'cover' }} />
          <button onClick={() => deleteGalleryImage(g.id)}>✕</button>
        </div>
      ))}
    </div>
    <GalleryUploadButton eventId={event.id} onUploaded={refreshGallery} />
  </div>
)}
```

---

### 1.3 Create Event Form (Admin)

**Currently:** Form sends JSON body.

**Change:** Switch to `multipart/form-data` to support file upload.

**Add `banner_image` field:**
```jsx
<input
  type="file"
  name="banner_image"
  accept="image/jpeg,image/jpg,image/png,image/webp"
  // Max 5MB enforced by backend
/>
```

**Add `video_url` field (text input — no upload):**
```jsx
<input
  type="text"
  name="video_url"
  placeholder="https://youtube.com/watch?v=..."
/>
```

**How to submit with file:**
```js
const formData = new FormData();
formData.append('title', values.title);
formData.append('description', values.description);
formData.append('organizer_phone', values.organizer_phone);
formData.append('taluka', values.taluka);
formData.append('address', values.address);
formData.append('start_date', values.start_date);
formData.append('end_date', values.end_date);
formData.append('is_free', values.is_free ? '1' : '0');
formData.append('video_url', values.video_url || '');

// Tags array — repeat key for each value
values.tags.forEach(tag => formData.append('tags[]', tag));

// Banner image file
if (values.banner_image_file) {
  formData.append('banner_image', values.banner_image_file);
}

// Append other fields as needed ...

await axios.post('/admin/v2/createEvent', formData, {
  headers: { 'Content-Type': 'multipart/form-data' }
});
```

> ⚠️ Do **not** set `Content-Type: application/json` — use `multipart/form-data` only when a file is attached. If no banner image, you can still use JSON (banner_image field is optional).

---

### 1.4 Update Event Form (Admin)

Same as create, but:
- Pre-fill all fields from existing event data
- Show current banner with option to replace

```jsx
{/* Show existing banner */}
{event.banner_image_url && !newBannerFile && (
  <div>
    <img src={event.banner_image_url} alt="Current Banner" style={{ height: 120 }} />
    <button onClick={() => setNewBannerFile(null)}>Replace</button>
  </div>
)}

{/* File input for new banner */}
<input
  type="file"
  accept="image/jpeg,image/jpg,image/png,image/webp"
  onChange={e => setNewBannerFile(e.target.files[0])}
/>
```

Submit the same way as create — only send `banner_image` in FormData if user selected a new file. Old file is automatically deleted from S3 by the backend.

---

### 1.5 Gallery Upload Section (Admin — Completed Events Only)

Show this section only when `event.status === 'completed'`.

**Upload multiple images:**
```jsx
<input
  type="file"
  multiple
  accept="image/jpeg,image/jpg,image/png,image/webp"
  onChange={e => setGalleryFiles(Array.from(e.target.files))}
/>
<button onClick={uploadGallery}>Upload</button>
```

```js
const uploadGallery = async () => {
  const formData = new FormData();
  formData.append('event_id', event.id);
  formData.append('title', galleryTitle);         // optional
  formData.append('description', galleryDesc);    // optional

  // Repeat for each file
  galleryFiles.forEach(file => formData.append('images[]', file));

  await axios.post('/admin/v2/uploadEventGallery', formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
  });
};
```

**Delete a gallery image:**
```js
await axios.post('/admin/v2/deleteEventGallery', { gallery_id: galleryItem.id });
```

---

## 2. User App Changes

### 2.1 Event List Screen

**Add:** Show `banner_image_url` as card thumbnail.

```jsx
// React Native example
<Image
  source={{ uri: event.banner_image_url || DEFAULT_EVENT_IMAGE }}
  style={{ width: '100%', height: 180, borderRadius: 8 }}
  resizeMode="cover"
/>
```

---

### 2.2 Event Detail Screen

**Add these sections:**

**Banner image (full width hero):**
```jsx
<Image
  source={{ uri: event.banner_image_url || DEFAULT_EVENT_IMAGE }}
  style={{ width: screenWidth, height: 250 }}
  resizeMode="cover"
/>
```

**Video URL:**
```jsx
{event.video_url && (
  <TouchableOpacity onPress={() => Linking.openURL(event.video_url)}>
    <Text>Watch Event Video</Text>
  </TouchableOpacity>
  // OR use react-native-youtube-iframe / WebView for embedding
)}
```

**Gallery** (shown when `event.status === 'completed'`):
```js
// Call: POST /api/v2/getEventGallery  { "event_id": event.id }
```

```jsx
{event.status === 'completed' && galleries.length > 0 && (
  <FlatList
    data={galleries}
    numColumns={3}
    keyExtractor={item => item.id.toString()}
    renderItem={({ item }) => (
      <Image
        source={{ uri: item.path_url }}
        style={{ width: (screenWidth - 32) / 3, height: 100, margin: 2 }}
        resizeMode="cover"
      />
    )}
  />
)}
```

---

### 2.3 Create Event Screen (User)

**Add `banner_image` file picker:**

```jsx
// Using react-native-image-picker or expo-image-picker
import * as ImagePicker from 'expo-image-picker';

const pickBanner = async () => {
  const result = await ImagePicker.launchImageLibraryAsync({
    mediaTypes: ImagePicker.MediaTypeOptions.Images,
    quality: 0.8,
  });
  if (!result.canceled) setBannerImage(result.assets[0]);
};
```

**Preview after picking:**
```jsx
{bannerImage && (
  <Image source={{ uri: bannerImage.uri }} style={{ width: '100%', height: 200 }} />
)}
<Button title="Pick Banner Image" onPress={pickBanner} />
```

**Add `video_url` text input:**
```jsx
<TextInput
  placeholder="YouTube / Video URL (optional)"
  value={videoUrl}
  onChangeText={setVideoUrl}
  keyboardType="url"
/>
```

**Submit using FormData:**
```js
const formData = new FormData();
formData.append('title', title);
formData.append('description', description);
formData.append('organizer_phone', phone);
formData.append('taluka', taluka);
formData.append('address', address);
formData.append('start_date', startDate);
formData.append('end_date', endDate);
formData.append('is_free', isFree ? '1' : '0');

if (videoUrl) formData.append('video_url', videoUrl);

// Tags
tags.forEach(tag => formData.append('tags[]', tag));

// Banner image (React Native FormData format)
if (bannerImage) {
  formData.append('banner_image', {
    uri: bannerImage.uri,
    name: 'banner.jpg',
    type: 'image/jpeg',
  });
}

await axios.post('/api/v2/createEvent', formData, {
  headers: { 'Content-Type': 'multipart/form-data' }
});
```

---

### 2.4 Update Event Screen (User)

Same as create. Pre-fill all fields. Show existing banner:

```jsx
{event.banner_image_url && !newBannerImage && (
  <Image source={{ uri: event.banner_image_url }} style={{ width: '100%', height: 200 }} />
)}
<Button title="Change Banner" onPress={pickBanner} />
```

Only append `banner_image` to FormData if user picked a new one. Backend deletes old S3 file automatically.

---

### 2.5 Gallery Upload (User — My Completed Events)

Show gallery upload option only on events owned by the user with `status === 'completed'`.

```js
// Fetch gallery: POST /api/v2/getEventGallery  { "event_id": event.id }

// Upload:
const formData = new FormData();
formData.append('event_id', event.id);
pickedImages.forEach((img, i) => {
  formData.append('images[]', {
    uri: img.uri,
    name: `gallery_${i}.jpg`,
    type: 'image/jpeg',
  });
});

await axios.post('/api/v2/uploadEventGallery', formData, {
  headers: { 'Content-Type': 'multipart/form-data' }
});

// Delete one:
await axios.post('/api/v2/deleteEventGallery', { gallery_id: item.id });
```

---

## 3. Field Rules Summary

| Field | Create | Update | Notes |
|---|---|---|---|
| `banner_image` | Optional file | Optional file | `jpeg/jpg/png/webp`, max **5 MB** |
| `video_url` | Optional string | Optional string | Must be a valid URL |
| Gallery upload | ❌ Not on create | ❌ Not on update | Separate endpoint, **completed events only** |
| `tags[]` | Optional array | Optional array | Repeat key per value in form-data |

## 4. Response Fields to Use

| Field | Where | Use |
|---|---|---|
| `banner_image_url` | Event object | Display banner — full S3 URL |
| `video_url` | Event object | Link/embed video |
| `path_url` | Gallery item | Display gallery image — full S3 URL |
| `banner_image` | Event object | **Ignore** — raw storage path |
| `path` | Gallery item | **Ignore** — raw storage path |
