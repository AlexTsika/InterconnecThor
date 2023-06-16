<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    // Show the registration form
    public function showRegisterForm()
    {
        $courses = Course::all(); // fetch all courses from the database
        return view('register', compact('courses')); // pass the courses to the register view
    }

    // Handle the registration request
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => 'required|max:255',
            'last_name' => 'required|max:255',
            'name' => 'required|max:255|unique:users', // validates that the name is required, is a string, is unique in the users table, and has a maximum length of 255 characters
            'email' => 'required|email|unique:users',
            'phone' => 'required',
            'street_nr' => 'required',
            'postal_code' => 'required',
            'city' => 'required',
            'country' => 'required',
            'password' => 'required|min:6|confirmed',
            'courses' => 'required|array|min:1', // validates that at least 1 course is selected
            'courses.*' => 'exists:courses,id', // validates that every value in the courses array exists as a course id
            'start_years' => 'required|array|min:1', // validates that at least 1 start year is provided
            'start_years.*' => 'required|integer|min:1900|max:'.(date('Y') + 2), // validates that every start year is an integer and is required and within the specified range
            'end_years' => 'required|array|min:1', // validates that at least 1 end year is provided
            'end_years.*' => 'required|integer|min:1900|max:'.(date('Y') + 4).'|gte:start_years.*', // validates that every end year is an integer and is required and within the specified range and at least the corresponding start year
            // 'profile_picture' => [
            //     File::types(['png', 'jpg', 'jpeg', 'gif'])->max(5120), // validates that the file is a png, jpg, jpeg, or gif and is smaller than 5120 kilobytes (5 megabytes)
            // ],
            'profile_picture' => 'image|mimes:jpeg,png,jpg,gif,svg|max:5120', // validates that the file is an image and is smaller than 5120 kilobytes (5 megabytes)

        ]);

        // Handle the profile picture upload
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');

            if ($file->isValid()) {
                $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('profile-pictures', $filename, 'public');

                // Save the file path or filename to the "profile_picture" column in the "students" table
                $profilePicturePath = $path;
            } else {
                return redirect()->back()->withErrors(['profile_picture' => 'The profile picture upload failed.']);
            }
        }

        // Save the registration details to the users table with the role_id column set to 2 (student)
        $user = new User;
        $user->first_name = $request->input('first_name');
        $user->last_name = $request->input('last_name');
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        $user->phone = $request->input('phone');
        $user->street_nr = $request->input('street_nr');
        $user->postal_code = $request->input('postal_code');
        $user->city = $request->input('city');
        $user->country = $request->input('country');
        $user->password = bcrypt($request->input('password'));
        // set role_id column to 2 (student)
        $user->role_id = 2;

        if (isset($profilePicturePath)) {
            $user->profile_picture = $profilePicturePath;
        }

        $user->save();

        // Save the courses and their details to the pivot table
        foreach ($request->courses as $key => $course_id) {
            $start_year = $request->start_years[$key];
            $end_year = $request->end_years[$key];

            $course = Course::findOrFail($course_id);
            $categories = $course->category()->pluck('id')->toArray();

            $user->categories()->attach($categories);
            $user->courses()->attach($course_id, ['start_year' => $start_year, 'end_year' => $end_year]);
        }

        return redirect()->route('home')->with('success', 'Registration successful, please wait for approval!');
    }

    // Show the login form
    public function showLoginForm()
    {
        return view('login');
    }

    // Handle the login request
    public function login(Request $request)
    {
        // Validate the login form data
        $request->validate([
            'identifier' => 'required',
            'password' => 'required',
        ]);
    
        // Identify the field type
        $field = $this->identifyFieldType($request->input('identifier'));
    
        // Perform authentication logic here (e.g., check credentials against the database)
        $credentials = [
            $field => $request->input('identifier'),
            'password' => $request->input('password'),
            'role_id' => 2, // Check if the role_id of the user is 2
            'approved' => 1 // Check if the user is approved
        ];
    
        if (Auth::attempt($credentials)) {
            // Authentication successful
            return redirect()->route('home')->with('success', 'Logged in successfully!');
        } else {
            // Authentication failed
            return redirect()->back()->withErrors([$field => 'Invalid ' . $field . ' or password.']);
        }
    }
    
    /**
     * Identify if the field is an email or a username.
     *
     * @param  string  $identifier
     * @return string
     */
    private function identifyFieldType(string $identifier)
    {
        return Validator::make(compact('identifier'), ['identifier' => 'email'])->fails() ? 'name' : 'email';
    }    

    // Handle the logout request
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Logged out successfully!');
    }

    // Show the forgot password form
    public function showForgotPasswordForm()
    {
        return view('forgot-password');
    }

    // Show reset password form
    public function showResetPasswordForm($token)
    {
        return view('reset-password', ['token' => $token]);
    }

    // reset password
    public function updatePassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|confirmed|min:6',
        ]);

        $passwordReset = DB::table('password_resets')
            ->where('token', hash('sha256', $request->token))
            ->first();

        if (!$passwordReset) {
            // Token not found
            return redirect()->back()->withErrors(['email' => 'This password reset token is invalid.']);
        }

        $user = User::where('email', $passwordReset->email)->first();

        if (!$user) {
            // User not found
            return redirect()->back()->withErrors(['email' => 'We can\'t find a user with that email address.']);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the token
        DB::table('password_resets')->where('email', $user->email)->delete();

        // Redirect to login with success message
        return redirect()->route('login')->with(['message' => 'Your password has been changed!']);
    }


   // Search
    public function search(Request $request)
    {
        $request->validate([
            'search' => 'required|min:3',
        ]);

        $search = $request->input('search');
        $currentUserId = Auth::id();  // Get the currently logged in user's ID

        // Search for user's name, city, postal code, courses, and categories
        $users = User::query()
            ->where('id', '!=', $currentUserId)  // Exclude the currently logged in user from the search results
            ->where(function ($query) use ($search) {
                $query->where('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%')
                    ->orWhere('postal_code', 'like', '%' . $search . '%')
                    ->orWhereHas('courses', function ($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('categories', function ($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%');
                    });
            })
            // Only show approved users with role_id 2
            ->where('approved', 1)
            ->where('role_id', 2)
            ->get();

        return view('search', compact('users'));
    }

    public function showUserProfile()
    {
        // Retrieve the currently authenticated user
        $user = auth()->user();

        // Pass the user data to the view
        return view('userProfile', compact('user'));
    }

    public function viewProfile($id)
    {
        // Retrieve user data based on the provided ID
        $user = User::find($id);

        if (!$user) {
            // Handle case when user is not found
            abort(404);
        }

        // Pass the user data to the profile view
        return view('searchProfile', ['user' => $user]);
    }

    public function showUpdateProfile()
    {
        $courses = Course::all(); // Assuming you have a Course model

        return view('edit-profile', compact('courses'));
    }

    public function updateProfile(Request $request)
    {
        // Validation rules for the form fields
        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:255',
            'street_nr' => 'required|string|max:255',
            'postal_code' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            // Add validation rules for other fields as needed
        ];
    
        // Validate the form data
        $validatedData = $request->validate($rules);
    
        // Update the user's profile data
        $user = auth()->user();
        $user->update($validatedData);
    
        // Handle profile picture upload if provided
        if ($request->hasFile('profile_picture')) {
            $profilePicture = $request->file('profile_picture');
            $profilePicturePath = $profilePicture->store('profile-pictures', 'public');
            $user->profile_picture = $profilePicturePath;
            $user->save();
        }
    
        return redirect()->route('userProfile')->with('success', 'Profile updated successfully.');
    }    

}
