import { useEffect, useState } from 'react'
import { getProfilePhoto } from './profilePhotoService'

function photoUserId(value: string | null | undefined): string | null {
  if (!value) return null
  try {
    const path = new URL(value, 'https://local.invalid').pathname
    const match = path.match(/\/usuarios\/([^/]+)\/foto\/?$/)
    return match ? decodeURIComponent(match[1]) : null
  } catch {
    return null
  }
}

export function ProtectedPhoto({ photoUrl, name, className = '', fallbackText, onLoadError }: { photoUrl?: string | null; name: string; className?: string; fallbackText?: string; onLoadError?: () => void }) {
  const userId = photoUserId(photoUrl)
  const [photo, setPhoto] = useState<{ userId: string; source: string } | null>(null)

  useEffect(() => {
    if (!userId) return
    let active = true
    let objectUrl: string | null = null
    void getProfilePhoto(userId).then((blob) => {
      objectUrl = URL.createObjectURL(blob)
      if (active) setPhoto({ userId, source: objectUrl })
      else URL.revokeObjectURL(objectUrl)
    }).catch(() => { if (active) { setPhoto(null); onLoadError?.() } })
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [userId, onLoadError])

  return <span aria-hidden="true" className={`admin-avatar grid shrink-0 place-items-center overflow-hidden ${className}`}>
    {photo?.userId === userId ? <img alt="" className="size-full object-cover" src={photo.source}/> : fallbackText ?? name.trim().charAt(0).toLocaleUpperCase('es')}
  </span>
}
