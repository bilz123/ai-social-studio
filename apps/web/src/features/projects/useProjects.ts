import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ApiError } from '@/shared/lib/apiClient'
import { useToast } from '@/shared/toast/useToast'
import {
  createProject,
  deleteImage,
  deleteProject,
  fetchProject,
  fetchProjects,
  reorderImages,
  saveProject,
} from './api/projects'
import type { ProjectEnvelope, ProjectImage } from './api/projects'

export const projectKeys = {
  all: ['projects'] as const,
  lists: ['projects', 'list'] as const,
  list: (page: number) => ['projects', 'list', page] as const,
  detail: (id: string) => ['projects', 'detail', id] as const,
}

/** Signed image links last 10-15 minutes; refresh well before they run out. */
const LINK_REFRESH_MS = 8 * 60 * 1000

export const useProjectList = (page: number) =>
  useQuery({ queryKey: projectKeys.list(page), queryFn: () => fetchProjects(page) })

export const useProject = (id: string) =>
  useQuery({
    queryKey: projectKeys.detail(id),
    queryFn: () => fetchProject(id),
    refetchInterval: LINK_REFRESH_MS,
    staleTime: 60_000,
  })

export const messageOf = (error: unknown): string =>
  error instanceof ApiError ? error.message : 'Something went wrong. Try again.'

/** Creates an empty draft and opens it in the editor. */
export function useCreateProject() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: createProject,
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: projectKeys.lists })
      void navigate(`/create/${created.data.id}`)
    },
    onError: (error) =>
      toast.show({
        tone: 'error',
        title: 'Could not create the project',
        description: messageOf(error),
      }),
  })
}

/** "Save draft": the name and description. */
export function useSaveProject() {
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: saveProject,
    onSuccess: async (saved, variables) => {
      queryClient.setQueryData<ProjectEnvelope>(projectKeys.detail(variables.id), (old) => ({
        ...(old ?? {}),
        ...saved,
      }))
      await queryClient.invalidateQueries({ queryKey: projectKeys.lists })
      toast.show({ tone: 'success', title: 'Draft saved' })
    },
    onError: (error) =>
      toast.show({
        tone: 'error',
        title: 'Could not save the draft',
        description: messageOf(error),
      }),
  })
}

export function useDeleteProject() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const toast = useToast()
  return useMutation({
    mutationFn: deleteProject,
    onSuccess: async (_data, id) => {
      queryClient.removeQueries({ queryKey: projectKeys.detail(id) })
      await queryClient.invalidateQueries({ queryKey: projectKeys.lists })
      toast.show({ tone: 'success', title: 'Project deleted' })
      void navigate('/create', { replace: true })
    },
    onError: (error) =>
      toast.show({
        tone: 'error',
        title: 'Could not delete the project',
        description: messageOf(error),
      }),
  })
}

/** Rewrites the cached project's image list (and count) without a round trip. */
export function patchImages(
  old: ProjectEnvelope | undefined,
  change: (images: ProjectImage[]) => ProjectImage[],
): ProjectEnvelope | undefined {
  if (!old) return old
  const images = change(old.data.images ?? []).map((image, index) => ({
    ...image,
    position: index + 1,
  }))
  return { ...old, data: { ...old.data, images, image_count: images.length } }
}

export function useDeleteImage(projectId: string) {
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: (imageId: string) => deleteImage({ projectId, imageId }),
    onSuccess: async (_data, imageId) => {
      queryClient.setQueryData<ProjectEnvelope>(projectKeys.detail(projectId), (old) =>
        patchImages(old, (images) => images.filter((image) => image.id !== imageId)),
      )
      await queryClient.invalidateQueries({ queryKey: projectKeys.lists })
      toast.show({ tone: 'success', title: 'Image removed' })
    },
    onError: async (error) => {
      await queryClient.invalidateQueries({ queryKey: projectKeys.detail(projectId) })
      toast.show({
        tone: 'error',
        title: 'Could not remove the image',
        description: messageOf(error),
      })
    },
  })
}

/** Optimistic: the grid moves at once and snaps back if the server refuses. */
export function useReorderImages(projectId: string) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const key = projectKeys.detail(projectId)
  return useMutation({
    mutationFn: (order: string[]) => reorderImages({ projectId, order }),
    onMutate: async (order) => {
      await queryClient.cancelQueries({ queryKey: key })
      const previous = queryClient.getQueryData<ProjectEnvelope>(key)
      queryClient.setQueryData<ProjectEnvelope>(key, (old) =>
        patchImages(old, (images) =>
          order
            .map((id) => images.find((image) => image.id === id))
            .filter((image): image is ProjectImage => image !== undefined),
        ),
      )
      return { previous }
    },
    onError: async (error, _order, context) => {
      if (context?.previous) queryClient.setQueryData(key, context.previous)
      await queryClient.invalidateQueries({ queryKey: key })
      toast.show({
        tone: 'error',
        title: 'Could not change the order',
        description: messageOf(error),
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: projectKeys.lists })
    },
  })
}
