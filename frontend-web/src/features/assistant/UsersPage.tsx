import { isAxiosError } from 'axios'
import { Plus, UserRound } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { EmptyState } from '../../components/states/EmptyState'
import { ErrorState } from '../../components/states/ErrorState'
import { LoadingState } from '../../components/states/LoadingState'
import { AccessibleDialog } from '../../components/ui/AccessibleDialog'
import { AssistantCreateForm } from '../modules/AssistantCreateForm'
import { AssistantPasswordForm } from './AssistantPasswordForm'
import { PatientAccountsSection } from './PatientAccountsSection'
import { activateAssistant, changeAssistantPassword, createAssistant, deactivateAssistant, deleteAssistant, getAssistants, type Assistant, type ChangeAssistantPasswordValues, type CreateAssistantValues } from './assistantService'

type State = { kind: 'loading' } | { kind: 'ready'; assistants: Assistant[] } | { kind: 'error'; message: string }
type Confirmation = { action: 'desactivar' | 'eliminar'; assistant: Assistant }
type Mutation = 'create' | 'status' | 'delete' | 'password'

function requestError(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    if (!error.response) return 'No fue posible comunicarse con el servidor. Inténtalo de nuevo.'
    if ([404, 409, 422].includes(error.response.status)) {
      const message = (error.response.data as { message?: unknown } | undefined)?.message
      if (typeof message === 'string' && message.trim()) return message
    }
  }
  return fallback
}

export function UsersPage() {
  const [state, setState] = useState<State>({ kind: 'loading' })
  const [createOpen, setCreateOpen] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [passwordError, setPasswordError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null)
  const [passwordTarget, setPasswordTarget] = useState<Assistant | null>(null)
  const [mutation, setMutation] = useState<Mutation | null>(null)
  const mutationPending = useRef(false)
  const requestId = useRef(0)
  const actionTrigger = useRef<HTMLButtonElement | null>(null)
  const createTrigger = useRef<HTMLButtonElement>(null)

  const load = useCallback(async (): Promise<State> => {
    const id = ++requestId.current
    setState({ kind: 'loading' })
    try {
      const assistants = await getAssistants()
      const next: State = { kind: 'ready', assistants }
      if (id === requestId.current) setState(next)
      return next
    } catch (error) {
      const next: State = { kind: 'error', message: requestError(error, 'No fue posible cargar las cuentas de asistentes.') }
      if (id === requestId.current) setState(next)
      return next
    }
  }, [])

  useEffect(() => {
    const requests = requestId
    void Promise.resolve().then(load)
    return () => { requests.current++ }
  }, [load])

  const begin = (kind: Mutation) => {
    if (mutationPending.current) return false
    mutationPending.current = true
    setMutation(kind)
    setActionError(null)
    setSuccess(null)
    return true
  }
  const finish = () => { mutationPending.current = false; setMutation(null) }
  const closeCreate = () => {
    if (mutationPending.current) return
    setCreateOpen(false)
    setCreateError(null)
    createTrigger.current?.focus()
  }
  const closeAction = () => {
    if (mutationPending.current) return
    setConfirmation(null)
    setPasswordTarget(null)
    setPasswordError(null)
    actionTrigger.current?.focus()
  }

  const onCreate = async (values: CreateAssistantValues): Promise<boolean> => {
    if (!begin('create')) return false
    setCreateError(null)
    try {
      await createAssistant(values)
      setCreateOpen(false)
      if ((await load()).kind === 'ready') setSuccess('Cuenta de asistente creada correctamente.')
      else setActionError('La cuenta se creó, pero no fue posible actualizar el listado.')
      createTrigger.current?.focus()
      return true
    } catch (error) {
      setCreateError(requestError(error, 'No fue posible crear la cuenta de asistente.'))
      if (isAxiosError(error) && error.response?.status === 409) await load()
      return false
    } finally { finish() }
  }

  const onToggle = async (assistant: Assistant): Promise<void> => {
    if (!begin('status')) return
    try {
      if (assistant.activo) await deactivateAssistant(assistant.id)
      else await activateAssistant(assistant.id)
      if ((await load()).kind === 'ready') setSuccess(assistant.activo ? 'Cuenta de asistente desactivada.' : 'Cuenta de asistente activada.')
      else setActionError('El estado cambió, pero no fue posible actualizar el listado.')
    } catch (error) {
      setActionError(requestError(error, 'No fue posible actualizar el estado de la cuenta.'))
      if (isAxiosError(error) && [404, 409].includes(error.response?.status ?? 0)) await load()
    } finally { finish(); setConfirmation(null); actionTrigger.current?.focus() }
  }

  const onDelete = async (assistant: Assistant): Promise<void> => {
    if (!begin('delete')) return
    try {
      await deleteAssistant(assistant.id)
      const refreshed = await load()
      if (refreshed.kind === 'ready' && !refreshed.assistants.some(({ id }) => id === assistant.id)) setSuccess('Cuenta de asistente eliminada correctamente.')
      else if (refreshed.kind === 'ready') setActionError('El servidor todavía devuelve esta cuenta. Vuelve a consultar antes de repetir la eliminación.')
    } catch (error) {
      setActionError(requestError(error, 'No fue posible eliminar la cuenta de asistente.'))
      if (isAxiosError(error) && error.response?.status === 404) await load()
    } finally { finish(); setConfirmation(null); actionTrigger.current?.focus() }
  }

  const onChangePassword = async (values: ChangeAssistantPasswordValues): Promise<boolean> => {
    if (!passwordTarget || !begin('password')) return false
    setPasswordError(null)
    try {
      await changeAssistantPassword(passwordTarget.id, values)
      setSuccess(`Contraseña de ${passwordTarget.name} actualizada.`)
      setPasswordTarget(null)
      actionTrigger.current?.focus()
      return true
    } catch (error) {
      setPasswordError(requestError(error, 'No fue posible cambiar la contraseña.'))
      if (isAxiosError(error) && error.response?.status === 404) await load()
      return false
    } finally { finish() }
  }

  return <div className="admin-reveal max-w-[1160px]">
    <div className="flex flex-wrap items-end justify-between gap-4 pb-7"><div><p className="admin-kicker">Administración</p><h1 className="admin-page-title mt-2">Usuarios</h1><p className="admin-body mt-2">Gestiona las cuentas del personal asistencial.</p></div><button className="admin-primary inline-flex min-h-11 items-center gap-2 rounded-[10px] px-5 text-sm font-semibold disabled:opacity-60" disabled={mutation !== null} onClick={() => { setCreateError(null); setCreateOpen(true) }} ref={createTrigger} type="button"><Plus aria-hidden="true" size={18}/>Crear asistente</button></div>
    {success ? <p className="admin-success mb-5 rounded-lg p-4 text-sm" role="status">{success}</p> : null}
    {actionError ? <p className="mb-5 text-sm text-red-700" role="alert">{actionError}</p> : null}
    {state.kind === 'loading' ? <LoadingState/> : state.kind === 'error' ? <ErrorState description={state.message} onRetry={() => { void load() }}/> : state.assistants.length === 0 ? <EmptyState description="No hay cuentas de asistentes registradas." icon={UserRound} title="Sin asistentes"/> : <ul className="grid gap-4">{state.assistants.map((assistant) => <li className="admin-surface rounded-[20px] p-5 sm:p-6" key={assistant.id}><div className="flex flex-wrap items-start justify-between gap-4"><div className="min-w-0"><h2 className="break-words text-lg font-semibold text-ink-950">{assistant.name}</h2><p className="admin-muted mt-1 break-all text-sm">{assistant.username} · {assistant.email ?? 'Correo no registrado'}</p></div><span className={`admin-pill ${assistant.activo ? 'admin-pill-ready' : 'admin-pill-pending'}`}>{assistant.activo ? 'Activa' : 'Inactiva'}</span></div><div className="mt-5 flex flex-wrap gap-2 border-t border-[#e6edf6] pt-4"><button className="admin-secondary min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-60" disabled={mutation !== null} onClick={(event) => { actionTrigger.current = event.currentTarget; if (assistant.activo) setConfirmation({ action: 'desactivar', assistant }); else void onToggle(assistant) }} type="button">{mutation === 'status' ? 'Actualizando…' : assistant.activo ? 'Desactivar' : 'Activar'}</button><button className="admin-secondary min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-60" disabled={mutation !== null} onClick={(event) => { actionTrigger.current = event.currentTarget; setPasswordTarget(assistant) }} type="button">Cambiar contraseña</button><button className="min-h-11 rounded-lg border border-red-200 px-4 text-sm font-semibold text-red-700 disabled:opacity-60" disabled={mutation !== null} onClick={(event) => { actionTrigger.current = event.currentTarget; setConfirmation({ action: 'eliminar', assistant }) }} type="button">Eliminar</button></div></li>)}</ul>}
    {createOpen ? <AccessibleDialog busy={mutation === 'create'} onClose={closeCreate} title="Crear cuenta de asistente" titleId="assistant-create-title"><AssistantCreateForm error={createError} onCancel={closeCreate} onCreate={onCreate}/></AccessibleDialog> : null}
    {confirmation ? <AccessibleDialog busy={mutation !== null} onClose={closeAction} title={confirmation.action === 'eliminar' ? 'Eliminar cuenta de asistente' : 'Desactivar cuenta de asistente'} titleId="assistant-confirm-title"><p className="admin-body text-sm">{confirmation.action === 'eliminar' ? `¿Eliminar la cuenta de ${confirmation.assistant.name}? Esta acción no se puede deshacer.` : `¿Desactivar la cuenta de ${confirmation.assistant.name}? Sus sesiones activas se cerrarán.`}</p><div className="mt-6 flex flex-wrap gap-3"><button className="min-h-11 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white disabled:opacity-60" data-dialog-initial disabled={mutation !== null} onClick={() => { if (confirmation.action === 'eliminar') void onDelete(confirmation.assistant); else void onToggle(confirmation.assistant) }} type="button">{mutation === 'delete' ? 'Eliminando…' : mutation === 'status' ? 'Actualizando…' : 'Confirmar'}</button><button className="admin-secondary min-h-11 rounded-lg px-4 text-sm font-semibold" disabled={mutation !== null} onClick={closeAction} type="button">Cancelar</button></div></AccessibleDialog> : null}
    {passwordTarget ? <AccessibleDialog busy={mutation !== null} onClose={closeAction} title={`Cambiar contraseña de ${passwordTarget.name}`} titleId="assistant-password-dialog-title"><AssistantPasswordForm disabled={mutation !== null} error={passwordError} onChangePassword={onChangePassword}/></AccessibleDialog> : null}
    <PatientAccountsSection/>
  </div>
}
