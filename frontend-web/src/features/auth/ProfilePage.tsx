import { useAuth } from './authContext'
import { ProfilePhotoControl } from './ProfilePhotoControl'
import { useOutletContext } from 'react-router-dom'

const roles = { doctora: 'Doctora', asistente: 'Asistente', paciente: 'Paciente' }

export function ProfilePage() {
  const { user } = useAuth()
  const photoUrl = useOutletContext<string | null | undefined>()
  if (!user) return null

  return <div className="admin-reveal max-w-3xl"><p className="admin-kicker">Cuenta personal</p><h1 className="admin-page-title mt-2">Mi perfil</h1>
    <section className="admin-surface mt-6 rounded-[20px] p-6 sm:p-8">
      <div className="flex items-center gap-4"><ProfilePhotoControl initialPhotoUrl={photoUrl} key={user.id}/><div><h2 className="text-xl font-semibold text-ink-950">{user.name}</h2><p className="admin-muted text-sm">{roles[user.role]}</p></div></div>
      <p className="admin-muted mt-4 text-sm">Selecciona tu fotografía para subir o eliminar tu propia imagen.</p>
      <dl className="mt-6 grid gap-x-8 sm:grid-cols-2"><div className="admin-fact"><dt className="admin-meta text-xs font-semibold uppercase tracking-wide">Usuario</dt><dd className="mt-2 break-words text-ink-950">{user.username}</dd></div>{user.email ? <div className="admin-fact"><dt className="admin-meta text-xs font-semibold uppercase tracking-wide">Correo</dt><dd className="mt-2 break-all text-ink-950">{user.email}</dd></div> : null}<div className="admin-fact"><dt className="admin-meta text-xs font-semibold uppercase tracking-wide">Rol</dt><dd className="mt-2 text-ink-950">{roles[user.role]}</dd></div></dl>
    </section>
  </div>
}
