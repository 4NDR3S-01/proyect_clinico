import { isAxiosError } from 'axios'
import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { AccessibleDialog } from '../../components/ui/AccessibleDialog'
import { useAuth } from './authContext'
import { deleteProfilePhoto, getProfilePhoto, uploadProfilePhoto } from './profilePhotoService'

const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp'])

function errorMessage(error: unknown): string {
  if (isAxiosError(error)) {
    if (!error.response) return 'No fue posible comunicarse con el servidor.'
    const message = (error.response.data as { message?: unknown } | undefined)?.message
    if (typeof message === 'string' && message.trim()) return message
  }
  return 'No fue posible actualizar la foto de perfil.'
}

export function ProfilePhotoControl() {
  const { user } = useAuth()
  const [photoUrl, setPhotoUrl] = useState<string | null>(user?.foto_perfil_url ?? null)
  const [revision, setRevision] = useState(0)
  const [blobUrl, setBlobUrl] = useState<string | null>(null)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loadError, setLoadError] = useState(false)
  const trigger = useRef<HTMLButtonElement>(null)
  const pending = useRef(false)

  useEffect(() => {
    if (!user || !photoUrl) return
    let active = true
    let objectUrl: string | null = null
    void getProfilePhoto(user.id).then((photo) => {
      objectUrl = URL.createObjectURL(photo)
      if (active) { setBlobUrl(objectUrl); setLoadError(false) }
      else URL.revokeObjectURL(objectUrl)
    }).catch(() => { if (active) setLoadError(true) })
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); setBlobUrl(null) }
  }, [user?.id, photoUrl, revision, user])

  if (!user) return null
  const close = () => { if (pending.current) return; setOpen(false); setError(null); trigger.current?.focus() }
  const upload = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file || pending.current) return
    if (!allowedTypes.has(file.type) || file.size > 5 * 1024 * 1024) { setError('Selecciona una imagen JPG, PNG o WebP de hasta 5 MB.'); return }
    pending.current = true; setBusy(true); setError(null)
    try { setPhotoUrl(await uploadProfilePhoto(file)); setRevision((value) => value + 1); setOpen(false); trigger.current?.focus() }
    catch (cause) { setError(errorMessage(cause)) }
    finally { pending.current = false; setBusy(false) }
  }
  const remove = async () => {
    if (pending.current) return
    pending.current = true; setBusy(true); setError(null)
    try { setPhotoUrl(await deleteProfilePhoto()); setBlobUrl(null); setOpen(false); trigger.current?.focus() }
    catch (cause) { setError(errorMessage(cause)) }
    finally { pending.current = false; setBusy(false) }
  }
  return <>
    <button aria-label="Gestionar foto de perfil" className="admin-interactive grid size-10 shrink-0 place-items-center overflow-hidden rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-clinic-600" onClick={() => { setError(null); setOpen(true) }} ref={trigger} type="button">
      {blobUrl && photoUrl ? <img alt="" className="size-full object-cover" src={blobUrl}/> : <span aria-hidden="true" className="admin-avatar grid size-full place-items-center rounded-full text-sm font-semibold">{user.name.trim().charAt(0).toLocaleUpperCase('es')}</span>}
    </button>
    {open ? <AccessibleDialog busy={busy} onClose={close} title="Foto de perfil" titleId="profile-photo-title">
      <p className="admin-body text-sm">Selecciona una imagen JPG, PNG o WebP de hasta 5 MB.</p>
      {loadError && photoUrl ? <p className="mt-3 text-sm text-amber-800" role="status">No fue posible mostrar la foto guardada. Puedes volver a intentarlo al abrir esta pantalla más tarde.</p> : null}
      {error ? <p className="mt-3 text-sm text-red-700" role="alert">{error}</p> : null}
      <div className="mt-5 flex flex-wrap items-center gap-3">
        <label className="admin-primary inline-flex min-h-11 cursor-pointer items-center rounded-lg px-4 text-sm font-semibold focus-within:outline-2 focus-within:outline-offset-2">{busy ? 'Guardando…' : 'Seleccionar foto'}<input accept="image/jpeg,image/png,image/webp" className="sr-only" data-dialog-initial disabled={busy} onChange={(event) => { void upload(event) }} type="file"/></label>
        {photoUrl ? <button className="admin-secondary min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-60" disabled={busy} onClick={() => { void remove() }} type="button">Eliminar foto</button> : null}
      </div>
    </AccessibleDialog> : null}
  </>
}
